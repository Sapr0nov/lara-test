<?php

namespace App\Contracts;

interface BlockchainAdapter
{
    /**
     * Получить информацию о транзакции
     */
    public function getTransaction(string $txHash): array;

    /**
     * Получить количество подтверждений
     */
    public function getConfirmations(string $txHash): int;

    /**
     * Отправить транзакцию в сеть
     */
    public function broadcastTransaction(string $rawTx): string;
}
