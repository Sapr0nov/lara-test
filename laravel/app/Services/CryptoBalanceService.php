<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Journal;
use App\Models\Wallet; // реализация вне тестового
use App\Support\CoinParams;  // реализация вне тестового количество подтверждений которые мы считаем нормальным
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CryptoBalanceService
{
    // операция зачисления
    public function createCreditOperation(
        Wallet $wallet,
        string $coin,
        string $txHash,
        string $amount,
        string $referenceType,
        int $referenceId
    ): Journal {

        if ($wallet->coin !== $coin) {
            throw new RuntimeException('Несоответствие монеты кошелька');
        }

        if (bccomp($amount, '0', 18) <= 0) {
            throw new RuntimeException('Сумма должна быть больше нуля');
        }

        // защита от повторного создания операции
        return Journal::firstOrCreate(
            [
                'wallet_id' => $wallet->id,
                'coin' => $coin,
                'tx_hash' => $txHash
            ],
            [
                'operation' => 'credit',
                'status' => TransactionStatus::New->value,
                'amount' => $amount,
                'confirmations' => 0,
                'required_confirmations' => CoinParams::confirmationsRequired($coin),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]
        );
    }

    // обновление подтверждений депозита
    public function updateCreditConfirmations(Journal $operation, int $confirmations): void
    {
        DB::transaction(function () use ($operation, $confirmations) {

            $operation = Journal::query()->lockForUpdate()->findOrFail($operation->id);

            if ($operation->operation !== 'credit') {
                throw new RuntimeException('Операция должна быть зачислением');
            }

            // если уже завершена — ничего не делаем
            if ($operation->status === TransactionStatus::Completed->value) {
                return;
            }

            $operation->confirmations = $confirmations;

            if ($confirmations >= $operation->required_confirmations) {

                // статус подтвержден
                $operation->status = TransactionStatus::Confirmed->value;

                // обновляем баланс кошелька
                $wallet = Wallet::query()->lockForUpdate()->findOrFail($operation->wallet_id);

                $wallet->available_balance = bcadd(
                    $wallet->available_balance,
                    $operation->amount,
                    18
                );

                $wallet->save();

                $operation->status = TransactionStatus::Completed->value;

            } else {

                $operation->status = TransactionStatus::Pending->value;

            }

            $operation->save();
        });
    }

    // операция списания
    public function createDebitOperation(
        Wallet $wallet,
        string $coin,
        string $amount,
        string $referenceType,
        int $referenceId
    ): Journal {

        if ($wallet->coin !== $coin) {
            throw new RuntimeException('Несоответствие монеты кошелька');
        }

        if (bccomp($amount, '0', 18) <= 0) {
            throw new RuntimeException('Сумма должна быть больше нуля');
        }

        return DB::transaction(function () use ($wallet, $coin, $amount, $referenceType, $referenceId) {

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            // проверка достаточности средств
            if (bccomp($wallet->available_balance, $amount, 18) < 0) {
                throw new RuntimeException('Недостаточно средств');
            }

            // перевод средств в locked
            $wallet->available_balance = bcsub(
                $wallet->available_balance,
                $amount,
                18
            );

            $wallet->locked_balance = bcadd(
                $wallet->locked_balance,
                $amount,
                18
            );

            $wallet->save();

            // создаем запись операции
            return Journal::create([
                'wallet_id' => $wallet->id,
                'coin' => $coin,
                'tx_hash' => null,
                'operation' => 'debit',
                'status' => TransactionStatus::New->value,
                'amount' => $amount,
                'confirmations' => 0,
                'required_confirmations' => CoinParams::confirmationsRequired($coin),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    // привязка tx_hash после отправки транзакции
    public function attachDebitTxHash(Journal $operation, string $txHash): void
    {
        DB::transaction(function () use ($operation, $txHash) {

            $operation = Journal::query()->lockForUpdate()->findOrFail($operation->id);

            if ($operation->operation !== 'debit') {
                throw new RuntimeException('Операция должна быть списанием');
            }

            if ($operation->status === TransactionStatus::Completed->value) {
                throw new RuntimeException('Операция уже завершена');
            }

            if ($operation->tx_hash !== null) {
                throw new RuntimeException('Хеш транзакции уже установлен');
            }

            $operation->tx_hash = $txHash;
            $operation->status = TransactionStatus::Pending->value;

            $operation->save();
        });
    }

    // завершение списания после подтверждений
    public function completeDebit(Journal $operation, int $confirmations): void
    {
        DB::transaction(function () use ($operation, $confirmations) {

            $operation = Journal::query()->lockForUpdate()->findOrFail($operation->id);

            if ($operation->operation !== 'debit') {
                throw new RuntimeException('Операция должна быть списанием');
            }

            if (!$operation->tx_hash) {
                throw new RuntimeException('Не указан хеш транзакции');
            }

            if ($operation->status === TransactionStatus::Completed->value) {
                return;
            }

            $operation->confirmations = $confirmations;

            // еще недостаточно подтверждений
            if ($confirmations < $operation->required_confirmations) {

                $operation->status = TransactionStatus::Pending->value;
                $operation->save();
                return;

            }

            // разблокируем средства
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($operation->wallet_id);

            $wallet->locked_balance = bcsub(
                $wallet->locked_balance,
                $operation->amount,
                18
            );

            $wallet->save();

            // операция завершена
            $operation->status = TransactionStatus::Completed->value;
            $operation->save();
        });
    }

    // откат неуспешного списания
    public function failDebit(Journal $operation): void
    {
        DB::transaction(function () use ($operation) {

            $operation = Journal::query()->lockForUpdate()->findOrFail($operation->id);

            if ($operation->operation !== 'debit') {
                throw new RuntimeException('Операция должна быть списанием');
            }

            // если уже финальный статус — ничего не делаем
            if (in_array($operation->status, [
                TransactionStatus::Completed->value,
                TransactionStatus::Failed->value,
                TransactionStatus::Reversed->value,
            ], true)) {
                return;
            }

            // возвращаем средства
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($operation->wallet_id);

            $wallet->locked_balance = bcsub(
                $wallet->locked_balance,
                $operation->amount,
                18
            );

            $wallet->available_balance = bcadd(
                $wallet->available_balance,
                $operation->amount,
                18
            );

            $wallet->save();

            // помечаем операцию как неуспешную
            $operation->status = TransactionStatus::Failed->value;
            $operation->save();
        });
    }
}
