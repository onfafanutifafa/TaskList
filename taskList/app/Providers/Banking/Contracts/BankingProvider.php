<?php

namespace App\Providers\Banking\Contracts;

/**
 * A banking-as-a-service partner: issues virtual receiving accounts (inbound) and
 * wires foreign currency out to external beneficiaries (outbound). Both directions
 * are the same partner, so one composite interface.
 */
interface BankingProvider extends VirtualAccountProvider, BankPayoutProvider
{
}
