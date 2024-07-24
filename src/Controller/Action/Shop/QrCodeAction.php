<?php

namespace SyliusMolliePlugin\Controller\Action\Shop;

use Mollie\Api\Resources\Payment;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use SyliusMolliePlugin\Client\MollieApiClient;
use SyliusMolliePlugin\DTO\MolliePayment\Amount;
use SyliusMolliePlugin\DTO\MolliePayment\Metadata;
use SyliusMolliePlugin\DTO\MolliePayment\MolliePayment;
use SyliusMolliePlugin\Entity\OrderInterface;
use SyliusMolliePlugin\Logger\MollieLoggerActionInterface;
use SyliusMolliePlugin\Resolver\MollieApiClientKeyResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class QrCodeAction
{
    private const PAYMENT_API = 'Payments API';

    /** @var MollieLoggerActionInterface */
    private $loggerAction;

    /** @var CartContextInterface */
    private $cartContext;

    /** @var MollieApiClient */
    private $mollieApiClient;

    /** @var MollieApiClientKeyResolverInterface */
    private $apiClientKeyResolver;

    /** @var \Sylius\Component\Core\Repository\OrderRepositoryInterface */
    private $orderRepository;

    /** @var UrlGeneratorInterface */
    private $urlGenerator;

    /** @var RepositoryInterface */
    private $methodRepository;

    /**
     * QrCodeAction constructor
     */
    public function __construct(
        MollieLoggerActionInterface $loggerAction,
        CartContextInterface $cartContext,
        MollieApiClient $mollieApiClient,
        MollieApiClientKeyResolverInterface $apiClientKeyResolver,
        OrderRepositoryInterface $orderRepository,
        UrlGeneratorInterface $urlGenerator,
        RepositoryInterface $methodRepository
    )
    {
        $this->loggerAction = $loggerAction;
        $this->cartContext = $cartContext;
        $this->mollieApiClient = $mollieApiClient;
        $this->apiClientKeyResolver = $apiClientKeyResolver;
        $this->orderRepository = $orderRepository;
        $this->urlGenerator = $urlGenerator;
        $this->methodRepository = $methodRepository;
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function createPayment(Request $request): Response
    {
        $method = $this->methodRepository->findOneBy(['methodId' => $request->get('paymentMethod')]);
        $qrCodeEnabled = $method->isQrCodeEnabled();

        if ($qrCodeEnabled) {
            /** @var OrderInterface $order */
            $order = $this->cartContext->getCart();
            $molliePayment = $this->buildPaymentObject($request, $order);

            try {
                $this->mollieApiClient->setApiKey($this->apiClientKeyResolver->getClientWithKey()->getApiKey());
                /** @var Payment $payment */
                $payment = $this->mollieApiClient->payments->create($molliePayment->toArray(), ['include' => 'details.qrCode']);

                // save qr code to the db order
                $qrCodeObject = $payment->details->qrCode;
                $this->setQrCodeOnOrder($order, $qrCodeObject->src);
                $this->setMolliePaymentIdOnOrder($order, $payment->id);

                return new JsonResponse(['qrCode' => $qrCodeObject], Response::HTTP_OK);
            } catch (\Exception $e) {
                $this->loggerAction->addNegativeLog(sprintf('Error with payment creation: %s', $e->getMessage()));
            }
        }

        return new JsonResponse(['qrCode' => null], Response::HTTP_OK);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function fetchQrCodeFromOrder(Request $request): JsonResponse
    {
        /** @var OrderInterface $order */
        $order = $this->cartContext->getCart();
        $qrCode = null;
        $orderId = $request->get('orderId');

        if ($orderId) {
            $order = $this->orderRepository->findOneBy(['id' => $orderId]);
        }

        if ($order) {
            $qrCode = $order->getQrCode();
        }

        return new JsonResponse(['qrCode' => $qrCode, 'orderId' => $order->getId()], Response::HTTP_OK);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function removeQrCodeFromOrder(Request $request): JsonResponse
    {
        $shouldDeletePaymentId = (bool)$request->get('shouldDeletePaymentId');

        /** @var OrderInterface $order */
        $order = $this->cartContext->getCart();
        $orderToken = $request->get('orderToken');
        if ($orderToken) {
            $order = $this->orderRepository->findOneByTokenValue($orderToken);
        }
        $this->setQrCodeOnOrder($order, null, $shouldDeletePaymentId);

        return new JsonResponse(['status' => Response::HTTP_OK]);
    }

    /**
     * @param OrderInterface $order
     * @param string|null $qrCode
     * @param bool $shouldDeletePaymentId
     *
     * @return void
     */
    private function setQrCodeOnOrder(OrderInterface $order, ?string $qrCode = null, bool $shouldDeletePaymentId = false)
    {
        try {
            $order->setQrCode($qrCode);
            if ($shouldDeletePaymentId) {
                $order->setMolliePaymentId(null);
            }
            $this->orderRepository->add($order);
        } catch (\Exception $exception) {
            $this->loggerAction->addNegativeLog(sprintf('Could not update qr code url on order: %s', $e->getMessage()));
        }
    }

    /**
     * @param OrderInterface $order
     * @param string|null $molliePaymentId
     *
     * @return void
     */
    private function setMolliePaymentIdOnOrder(OrderInterface $order, ?string $molliePaymentId = null)
    {
        try {
            $order->setMolliePaymentId($molliePaymentId);
            $this->orderRepository->add($order);
        } catch (\Exception $exception) {
            $this->loggerAction->addNegativeLog(sprintf('Could not update mollie payment id on order: %s', $e->getMessage()));
        }
    }

    /**
     * Create mollie payment dto
     *
     * @param Request $request
     * @param OrderInterface $order
     *
     * @return MolliePayment
     */
    private function buildPaymentObject(Request $request, OrderInterface $order): MolliePayment
    {
        $molliePayment = new MolliePayment();
        $molliePayment->setAmount(new Amount((string)($order->getTotal() / 100), $order->getCurrencyCode()));
        $molliePayment->setMethod($request->get('paymentMethod'));
        $molliePayment->setDescription((string)$order->getId());
        $molliePayment->setIssuer($request->get('issuer') ?? '');
        $redirectUrl = $this->urlGenerator->generate('sylius_mollie_plugin_payum', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $webhookUrl = $this->urlGenerator->generate('sylius_mollie_plugin_payment_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $redirectUrl .= '?orderId=' . $order->getId();
        $webhookUrl .= '?orderId=' . $order->getId();
        $molliePayment->setWebhookUrl($webhookUrl);
        $molliePayment->setRedirectUrl($redirectUrl);

        $metadata = new Metadata(
            $order->getId(),
            (string)$order->getCustomer()->getId(),
            $request->get('paymentMethod'),
            null,
            null,
            null,
            $request->get('issuer') ?? '',
            self::PAYMENT_API,
        );
        $molliePayment->setMetadata($metadata);
        $molliePayment->setCustomerId(null);
        $molliePayment->setLocale($order->getLocaleCode());

        return $molliePayment;
    }
}
