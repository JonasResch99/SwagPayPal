<?php declare(strict_types=1);

namespace Swag\PayPal\Util;

use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Swag\PayPal\Payment\PayPalPaymentController;
use Symfony\Component\HttpFoundation\Request;

class PaymentStatusUtil
{
    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /** @var EntityRepositoryInterface */
    private $orderTransactionRepository;

    public function __construct(
        StateMachineRegistry $stateMachineRegistry,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderTransactionRepository
    ) {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws OrderNotFoundException
     * @throws StateMachineNotFoundException
     * @throws InvalidOrderException
     */
    public function applyVoidStateToOrder(string $orderId, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $this->transisitionOrderTransactionState($transaction, StateMachineTransitionActions::ACTION_CANCEL, $context);
    }

    /**
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws OrderNotFoundException
     * @throws StateMachineNotFoundException
     * @throws InvalidOrderException
     */
    public function applyCaptureStateToPayment(string $orderId, Request $request, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $amountToCapture = round((float) $request->request->get(PayPalPaymentController::REQUEST_PARAMETER_CAPTURE_AMOUNT), 2);
        $isFinalCapture = $request->request->getBoolean(PayPalPaymentController::REQUEST_PARAMETER_CAPTURE_IS_FINAL, true);

        if ($isFinalCapture || $amountToCapture === $transaction->getAmount()->getTotalPrice()) {
            $this->transisitionOrderTransactionState($transaction, StateMachineTransitionActions::ACTION_PAY, $context);

            return;
        }

        $this->transisitionOrderTransactionState($transaction, StateMachineTransitionActions::ACTION_PAY_PARTIALLY, $context);
    }

    /**
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws OrderNotFoundException
     * @throws StateMachineNotFoundException
     * @throws InvalidOrderException
     */
    public function applyRefundStateToPayment(string $orderId, Request $request, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $refundAmount = round((float) $request->request->get(PayPalPaymentController::REQUEST_PARAMETER_REFUND_AMOUNT), 2);

        if ($refundAmount === $transaction->getAmount()->getTotalPrice()) {
            $this->transisitionOrderTransactionState($transaction, StateMachineTransitionActions::ACTION_REFUND, $context);

            return;
        }

        $this->transisitionOrderTransactionState($transaction, StateMachineTransitionActions::ACTION_REFUND_PARTIALLY, $context);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws OrderNotFoundException
     * @throws InvalidOrderException
     */
    private function getOrderTransaction(string $orderId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        $transactionCollection = $order->getTransactions();

        if ($transactionCollection === null) {
            throw new InvalidOrderException($orderId);
        }

        $transaction = $transactionCollection->first();

        if ($transaction === null) {
            throw new InvalidOrderException($orderId);
        }

        return $transaction;
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws IllegalTransitionException
     * @throws StateMachineNotFoundException
     */
    private function transisitionOrderTransactionState(OrderTransactionEntity $orderTransactionEntity, string $transistionName, Context $context): void
    {
        $toPlace = $this->stateMachineRegistry->transition($this->stateMachineRegistry->getStateMachine(OrderTransactionStates::STATE_MACHINE, $context),
            $orderTransactionEntity->getStateMachineState(),
            $this->orderTransactionRepository->getDefinition()->getEntityName(),
            $orderTransactionEntity->getId(),
            $context,
            $transistionName);

        $payload = [
            ['id' => $orderTransactionEntity->getId(), 'stateId' => $toPlace->getId()],
        ];

        $this->orderTransactionRepository->update($payload, $context);
        $orderTransactionEntity->setStateMachineState($toPlace);
        $orderTransactionEntity->setStateId($toPlace->getId());
    }
}