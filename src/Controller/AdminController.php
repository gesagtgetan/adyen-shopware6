<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen plugin for Shopware 6
 *
 * Copyright (c) 2021 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 */

namespace Adyen\Shopware\Controller;

use Adyen\AdyenException;
use Adyen\Client;
use Adyen\Service\Checkout;
use Adyen\Shopware\Entity\Notification\NotificationEntity;
use Adyen\Shopware\Entity\Refund\RefundEntity;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\NotificationService;
use Adyen\Shopware\Service\RefundService;
use Adyen\Shopware\Service\Repository\AdyenRefundRepository;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Adyen\Util\Currency;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"administration"})
 *
 * Class AdminController
 * @package Adyen\Shopware\Controller
 */
class AdminController
{
    const ADMIN_DATETIME_FORMAT = 'Y-m-d H:i (e)';

    /** @var LoggerInterface */
    private $logger;

    /** @var OrderRepository */
    private $orderRepository;

    /** @var RefundService */
    private $refundService;

    /** @var AdyenRefundRepository */
    private $adyenRefundRepository;

    /**
     * @var NotificationService
     */
    private NotificationService $notificationService;

    /** @var CurrencyFormatter */
    private $currencyFormatter;

    /** @var Currency */
    private $currencyUtil;

    /**
     * AdminController constructor.
     *
     * @param LoggerInterface $logger
     * @param OrderRepository $orderRepository
     * @param RefundService $refundService
     * @param AdyenRefundRepository $adyenRefundRepository
     * @param NotificationService $notificationService
     * @param CurrencyFormatter $currencyFormatter
     * @param Currency $currencyUtil
     */
    public function __construct(
        LoggerInterface $logger,
        OrderRepository $orderRepository,
        RefundService $refundService,
        AdyenRefundRepository $adyenRefundRepository,
        NotificationService $notificationService,
        CurrencyFormatter $currencyFormatter,
        Currency $currencyUtil
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->refundService = $refundService;
        $this->adyenRefundRepository = $adyenRefundRepository;
        $this->notificationService = $notificationService;
        $this->currencyFormatter = $currencyFormatter;
        $this->currencyUtil = $currencyUtil;
    }

    /**
     * @Route(path="/api/_action/adyen/verify")
     *
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        try {
            $client = new Client();
            $client->setXApiKey($dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.apiKeyTest'));
            $client->setEnvironment(
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.environment') ? 'live' : 'test',
                $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.liveEndpointUrlPrefix')
            );
            $service = new Checkout($client);

            $params = array(
                'merchantAccount' => $dataBag->get(ConfigurationService::BUNDLE_NAME . '.config.merchantAccount'),
            );
            $result = $service->paymentMethods($params);

            $hasPaymentMethods = isset($result['paymentMethods']);
            $response = ['success' => $hasPaymentMethods];
            if (!$hasPaymentMethods) {
                $response['message'] = 'adyen.paymentMethodsMissing';
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    /**
     * Send a refund operation to the Adyen platform
     *
     * @Route(
     *     "/api/adyen/refunds",
     *     name="api.adyen_refund.post",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function postRefund(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();
        $orderId = $request->request->get('orderId');
        $refundAmount = $request->request->get('refundAmount');
        // If payload does not contain orderNumber
        if (empty($orderId)) {
            $message = 'Order Id was not provided in request';
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        } elseif (empty($refundAmount)) {
            $message = 'Refund amount was not provided in request';
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        }


        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrder(
            $orderId,
            $context,
            ['transactions', 'currency']
        );

        if (is_null($order)) {
            $message = sprintf('Unable to find order %s', $orderId);
            $this->logger->error($message);
            return new JsonResponse($message, 400);
        } else {
            $currencyIso = $order->getCurrency()->getIsoCode();
            $amountInCents = $this->currencyUtil->sanitize($refundAmount, $currencyIso);
            if (!$this->refundService->isAmountRefundable($order, $amountInCents)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'adyen.invalidRefundAmount',
                ]);
            }
        }

        try {
            $result = $this->refundService->refund($order, $amountInCents);
            // If response does not contain pspReference
            if (!array_key_exists('pspReference', $result)) {
                $message = sprintf('Invalid response for refund on order %s', $order->getOrderNumber());
                throw new AdyenException($message);
            }

            $this->refundService->insertAdyenRefund(
                $order,
                $result['pspReference'],
                RefundEntity::SOURCE_SHOPWARE,
                RefundEntity::STATUS_PENDING_WEBHOOK,
                $amountInCents
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => 'adyen.refundError',
            ]);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route(
     *     "/api/adyen/orders/{orderId}/refunds",
     *     name="api.adyen_refund.get",
     *     methods={"GET"}
     * )
     *
     * @param string $orderId
     * @return JsonResponse
     */
    public function getRefunds(string $orderId): JsonResponse
    {
        $refunds = $this->adyenRefundRepository->getRefundsByOrderId($orderId);

        return new JsonResponse($this->buildRefundResponseData($refunds->getElements()));
    }

    /**
     * Get all the notifications for an order.
     *
     * @Route(
     *     "/api/adyen/orders/{orderId}/notifications",
     *      methods={"GET"}
 *     )
     * @param string $orderId
     * @return JsonResponse
     */
    public function getOrderNotifications(string $orderId): JsonResponse
    {
        $order = $this->orderRepository->getOrder($orderId, Context::createDefaultContext());
        $notifications = $this->notificationService->getAllNotificationsByOrderNumber($order->getOrderNumber());

        $response = [];
        /** @var NotificationEntity $notification */
        foreach ($notifications as $notification) {
            $response[] = [
                'pspReference' => $notification->getPspreference(),
                'eventCode' => $notification->getEventCode(),
                'success' => $notification->isSuccess(),
                'amount' => $notification->getAmountValue() . ' ' . $notification->getAmountCurrency(),
                'status' => $notification->isDone()
                    ? NotificationEntity::NOTIFICATION_STATUS_PROCESSED
                    : NotificationEntity::NOTIFICATION_STATUS_PENDING,
                'createdAt' => $notification->getCreatedAt()->format(self::ADMIN_DATETIME_FORMAT),
                'updatedAt' => $notification->getUpdatedAt()->format(self::ADMIN_DATETIME_FORMAT),
            ];
        }

        return new JsonResponse($response);
    }

    /**
     * Build a response containing the data related to the refunds
     *
     * @param array $refunds
     * @return array
     */
    private function buildRefundResponseData(array $refunds)
    {
        $context = Context::createDefaultContext();
        $result = [];
        /** @var RefundEntity $refund */
        foreach ($refunds as $refund) {
            $updatedAt = $refund->getUpdatedAt();
            $order = $refund->getOrderTransaction()->getOrder();
            $amount = $this->currencyFormatter->formatCurrencyByLanguage(
                $refund->getAmount() / 100,
                $order->getCurrency()->getIsoCode(),
                $order->getLanguageId(),
                $context
            );
            $result[] = [
                'pspReference' => $refund->getPspReference(),
                'amount' => $amount,
                'rawAmount' => $refund->getAmount(),
                'status' => $refund->getStatus(),
                'createdAt' => $refund->getCreatedAt()->format(self::ADMIN_DATETIME_FORMAT),
                'updatedAt' => is_null($updatedAt) ? '-' : $updatedAt->format(self::ADMIN_DATETIME_FORMAT)
            ];
        }

        return $result;
    }
}
