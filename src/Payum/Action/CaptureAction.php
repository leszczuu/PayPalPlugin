<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Payum\Action;

use GuzzleHttp\ClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class CaptureAction implements ActionInterface
{
    /** @var ClientInterface */
    private $httpClient;

    public function __construct(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /** @param Capture $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        $config = $paymentMethod->getGatewayConfig()->getConfig();

        $response = $this->httpClient->request(
            'POST',
            'https://api.sandbox.paypal.com/v1/oauth2/token',
            [
                'auth' => [$config['client_id'], $config['client_secret']],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]
        );

        /** @var array $content */
        $content = json_decode($response->getBody()->getContents(), true);

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $payment->getOrder()->getCurrencyCode(),
                        'value' => $payment->getAmount()/100,
                    ],
                    'payee' => [
                        // TODO: change hardcoded seller data
                        'email_address' => 'sb-ecyrw2404052@business.example.com',
                        'merchant_id' => 'L7WWW2B328J7J',
                    ],
                    // TODO: figure out how not to send this data in the prod env
                    'payment_instruction' => [
                        'disbursement_mode' => 'INSTANT',
                        'platform_fees' => [
                            [
                                'amount' => [
                                    'currency_code' => $payment->getOrder()->getCurrencyCode(),
                                    'value' => round(($payment->getAmount()/100)*0.02, 2),
                                ],
                                'payee' => [
                                    // TODO: change hardcoded facilitator data - or not (maybe it's not a problem)
                                    'email_address' => 'sb-nevei1350290@business.example.com',
                                    'merchant_id' => 'JQVY284FYA5PC',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpClient->request(
            'POST',
            'https://api.sandbox.paypal.com/v2/checkout/orders', [
                'headers' => [
                    'Authorization' => 'Bearer '.$content['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'PayPal-Partner-Attribution-Id' => 'sylius-ppcp4p-bn-code',
                ],
                'json' => $data
            ]
        );

        /** @var array $content */
        $content = json_decode($response->getBody()->getContents(), true);

        if ($content['status'] === 'CREATED') {
            $payment->setDetails([
                'status' => StatusAction::STATUS_CAPTURED,
                'paypal_order_id' => $content['id'],
            ]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof PaymentInterface
        ;
    }
}
