<?php

namespace App\Adapters;

use App\Contracts\BlockchainAdapter;

/** примерный вариант реализации для монеты биткоин **/
class BitcoinAdapter implements BlockchainAdapter
{
    public function getTransaction(string $txHash): array
    {
        /**
         * Заглушка RPC вызова
         * например:
         * getrawtransaction
         */

        return [
            'tx_hash' => $txHash,
            'confirmations' => 3,
            'amount' => 0.5
        ];
    }

    public function getConfirmations(string $txHash): int
    {
        /**
         * В реальности:
         * currentBlock - txBlock
         */

        return 3;
    }

    public function broadcastTransaction(string $rawTx): string
    {
        /**
         * RPC:
         * sendrawtransaction
         */

        return 'btc_tx_hash_example';
    }
}
