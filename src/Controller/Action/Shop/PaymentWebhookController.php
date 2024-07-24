<?php

namespace SyliusMolliePlugin\Controller\Action\Shop;

use Mollie\Api\Types\PaymentStatus;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use SyliusMolliePlugin\Client\MollieApiClient;
use SyliusMolliePlugin\Entity\OrderInterface;
use SyliusMolliePlugin\Resolver\MollieApiClientKeyResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController
{
    /** @var MollieApiClient */
    private $mollieApiClient;

    /** @var MollieApiClientKeyResolverInterface */
    private $apiClientKeyResolver;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var PaymentRepositoryInterface */
    private $paymentRepository;

    /**
     * PaymentWebhookController constructor
     */
    public function __construct(
        MollieApiClient $mollieApiClient,
        MollieApiClientKeyResolverInterface $apiClientKeyResolver,
        OrderRepositoryInterface $orderRepository,
        PaymentRepositoryInterface $paymentRepository,
    )
    {
        $this->mollieApiClient = $mollieApiClient;
        $this->apiClientKeyResolver = $apiClientKeyResolver;
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
    }

    /**
     * @param Request $request
     *
     * @return Response
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function __invoke(Request $request): Response
    {
        $this->mollieApiClient->setApiKey($this->apiClientKeyResolver->getClientWithKey()->getApiKey());
        $molliePayment = $this->mollieApiClient->payments->get($request->get('id'));

        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneBy(['id' => $request->get('orderId')]);
        if ($order === null) {
            return new JsonResponse(Response::HTTP_OK);
        }

        $payment = $order->getLastPayment();
        $status = $this->getStatus($molliePayment);

        if ($payment->getState() !== $status && PaymentInterface::STATE_UNKNOWN !== $status) {
            $payment->setState($status);
            $this->paymentRepository->add($payment);
        }

        return new JsonResponse(Response::HTTP_OK);
    }

    /**
     * @param $molliePayment
     *
     * @return string
     */
    private function getStatus($molliePayment)
    {
        switch ($molliePayment->status) {
            case PaymentStatus::STATUS_PENDING:
            case PaymentStatus::STATUS_OPEN:
                return PaymentInterface::STATE_PROCESSING;
            case PaymentStatus::STATUS_AUTHORIZED:
                return PaymentInterface::STATE_AUTHORIZED;
            case PaymentStatus::STATUS_PAID:
                return PaymentInterface::STATE_COMPLETED;
            case PaymentStatus::STATUS_CANCELED:
                return PaymentInterface::STATE_CANCELLED;
            case PaymentStatus::STATUS_FAILED:
                return PaymentInterface::STATE_FAILED;
            case PaymentStatus::STATUS_EXPIRED:
                return PaymentInterface::STATE_FAILED;
            default:
                return PaymentInterface::STATE_UNKNOWN;
        }
    }
}
