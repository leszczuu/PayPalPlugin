<?php

declare(strict_types=1);

namespace Tests\Sylius\PayPalPlugin\Behat\Context\Admin;

use Behat\Behat\Context\Context;
use Doctrine\Common\Persistence\ObjectManager;
use SM\Factory\FactoryInterface;
use Sylius\Behat\Page\Admin\Order\ShowPageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\StateMachine\StateMachineInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

final class ManagingOrdersContext implements Context
{
    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var ObjectManager */
    private $objectManager;

    /** @var KernelBrowser */
    private $client;

    /** @var ShowPageInterface */
    private $showPage;

    /** @var int */
    private $refundAmount;

    public function __construct(
        FactoryInterface $stateMachineFactory,
        ObjectManager $objectManager,
        KernelBrowser $client,
        ShowPageInterface $showPage
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->objectManager = $objectManager;
        $this->client = $client;
        $this->showPage = $showPage;
    }

    /**
     * @Given /^(this order) is already paid as "([^"]+)" PayPal order$/
     */
    public function thisOrderIsAlreadyPaidAsPayPalOrder(OrderInterface $order, string $payPalOrderId): void
    {
        /** @var PaymentInterface $payment */
        $payment = $order->getPayments()->first();
        $payment->setDetails(['paypal_order_id' => $payPalOrderId]);

        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        $this->refundAmount = $order->getTotal();

        $this->objectManager->flush();
    }

    /**
     * @When request from PayPal about :payPalOrderId order refund has been received
     */
    public function requestFromPayPalAboutOrderRefundHasBeenReceived(string $payPalOrderId): void
    {
        $data = json_encode([
            'resource_type' => 'refund',
            'resource' => [
                'id' => $payPalOrderId,
                'amount' => ['currency_code' => 'USD', 'amount' => (string) ($this->refundAmount/100)],
                'status' => 'COMPLETED',
            ],
        ]);

        $this->client->request('POST', '/paypal-webhook/api/', [], [], ['Content-Type' => 'application/json'], $data);

        Assert::same($this->client->getResponse()->getStatusCode(), Response::HTTP_NO_CONTENT);
    }

    /**
     * @When I view the summary of the refunded order :order
     */
    public function iSeeTheRefundedOrder(OrderInterface $order): void
    {
        // During calling `open` method it's verified and FOR SOME REASON it constantly tries to compare it
        // with the previously visited URL "/paypal-webhook/api/"
        // This override is temporary until we find out what is the problem
        $this->showPage->tryToOpen(['id' => $order->getId()]);
    }
}
