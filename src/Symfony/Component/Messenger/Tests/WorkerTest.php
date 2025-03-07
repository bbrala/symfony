<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessage;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @group time-sensitive
 */
class WorkerTest extends TestCase
{
    public function testWorkerDispatchTheReceivedMessage()
    {
        $apiMessage = new DummyMessage('API');
        $ipaMessage = new DummyMessage('IPA');

        $receiver = new DummyReceiver([
            [new Envelope($apiMessage), new Envelope($ipaMessage)],
        ]);

        $bus = $this->createMock(MessageBusInterface::class);

        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->withConsecutive(
                [new Envelope($apiMessage, [new ReceivedStamp('transport'), new ConsumedByWorkerStamp()])],
                [new Envelope($ipaMessage, [new ReceivedStamp('transport'), new ConsumedByWorkerStamp()])]
            )
            ->willReturnOnConsecutiveCalls(
                $this->returnArgument(0),
                $this->returnArgument(0)
            );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));

        $worker = new Worker(['transport' => $receiver], $bus, $dispatcher);
        $worker->run();

        $this->assertSame(2, $receiver->getAcknowledgeCount());
    }

    public function testHandlingErrorCausesReject()
    {
        $receiver = new DummyReceiver([
            [new Envelope(new DummyMessage('Hello'), [new SentStamp('Some\Sender', 'transport1')])],
        ]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \InvalidArgumentException('Why not'));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        $worker = new Worker(['transport1' => $receiver], $bus, $dispatcher);
        $worker->run();

        $this->assertSame(1, $receiver->getRejectCount());
        $this->assertSame(0, $receiver->getAcknowledgeCount());
    }

    public function testWorkerDoesNotSendNullMessagesToTheBus()
    {
        $receiver = new DummyReceiver([
            null,
        ]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event) {
            $event->getWorker()->stop();
        });

        $worker = new Worker([$receiver], $bus, $dispatcher);
        $worker->run();
    }

    public function testWorkerDispatchesEventsOnSuccess()
    {
        $envelope = new Envelope(new DummyMessage('Hello'));
        $receiver = new DummyReceiver([[$envelope]]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn($envelope);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher->expects($this->exactly(5))
            ->method('dispatch')
            ->withConsecutive(
                [$this->isInstanceOf(WorkerStartedEvent::class)],
                [$this->isInstanceOf(WorkerMessageReceivedEvent::class)],
                [$this->isInstanceOf(WorkerMessageHandledEvent::class)],
                [$this->isInstanceOf(WorkerRunningEvent::class)],
                [$this->isInstanceOf(WorkerStoppedEvent::class)]
            )->willReturnCallback(function ($event) {
                if ($event instanceof WorkerRunningEvent) {
                    $event->getWorker()->stop();
                }

                return $event;
            });

        $worker = new Worker([$receiver], $bus, $eventDispatcher);
        $worker->run();
    }

    public function testWorkerDispatchesEventsOnError()
    {
        $envelope = new Envelope(new DummyMessage('Hello'));
        $receiver = new DummyReceiver([[$envelope]]);

        $bus = $this->createMock(MessageBusInterface::class);
        $exception = new \InvalidArgumentException('Oh no!');
        $bus->method('dispatch')->willThrowException($exception);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher->expects($this->exactly(5))
            ->method('dispatch')
            ->withConsecutive(
                [$this->isInstanceOf(WorkerStartedEvent::class)],
                [$this->isInstanceOf(WorkerMessageReceivedEvent::class)],
                [$this->isInstanceOf(WorkerMessageFailedEvent::class)],
                [$this->isInstanceOf(WorkerRunningEvent::class)],
                [$this->isInstanceOf(WorkerStoppedEvent::class)]
            )->willReturnCallback(function ($event) {
                if ($event instanceof WorkerRunningEvent) {
                    $event->getWorker()->stop();
                }

                return $event;
            });

        $worker = new Worker([$receiver], $bus, $eventDispatcher);
        $worker->run();
    }

    public function testWorkerContainsMetadata()
    {
        $envelope = new Envelope(new DummyMessage('Hello'));
        $receiver = new DummyQueueReceiver([[$envelope]]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn($envelope);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event) {
            $event->getWorker()->stop();
        });

        $worker = new Worker(['dummyReceiver' => $receiver], $bus, $dispatcher);
        $worker->run(['queues' => ['queue1', 'queue2']]);

        $workerMetadata = $worker->getMetadata();

        $this->assertSame(['queue1', 'queue2'], $workerMetadata->getQueueNames());
        $this->assertSame(['dummyReceiver'], $workerMetadata->getTransportNames());
    }

    public function testTimeoutIsConfigurable()
    {
        $apiMessage = new DummyMessage('API');
        $receiver = new DummyReceiver([
            [new Envelope($apiMessage), new Envelope($apiMessage)],
            [], // will cause a wait
            [], // will cause a wait
            [new Envelope($apiMessage)],
            [new Envelope($apiMessage)],
            [], // will cause a wait
            [new Envelope($apiMessage)],
        ]);

        $bus = $this->createMock(MessageBusInterface::class);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(5));

        $worker = new Worker([$receiver], $bus, $dispatcher);
        $startTime = microtime(true);
        // sleep .1 after each idle
        $worker->run(['sleep' => 100000]);

        $duration = microtime(true) - $startTime;
        // wait time should be .3 seconds
        // use .29 & .31 for timing "wiggle room"
        $this->assertGreaterThanOrEqual(.29, $duration);
        $this->assertLessThan(.31, $duration);
    }

    public function testWorkerWithMultipleReceivers()
    {
        // envelopes, in their expected delivery order
        $envelope1 = new Envelope(new DummyMessage('message1'));
        $envelope2 = new Envelope(new DummyMessage('message2'));
        $envelope3 = new Envelope(new DummyMessage('message3'));
        $envelope4 = new Envelope(new DummyMessage('message4'));
        $envelope5 = new Envelope(new DummyMessage('message5'));
        $envelope6 = new Envelope(new DummyMessage('message6'));

        /*
         * Round 1) receiver 1 & 2 have nothing, receiver 3 processes envelope1 and envelope2
         * Round 2) receiver 1 has nothing, receiver 2 processes envelope3, receiver 3 is not called
         * Round 3) receiver 1 processes envelope 4, receivers 2 & 3 are not called
         * Round 4) receiver 1 processes envelope 5, receivers 2 & 3 are not called
         * Round 5) receiver 1 has nothing, receiver 2 has nothing, receiver 3 has envelope 6
         */
        $receiver1 = new DummyReceiver([
            [],
            [],
            [$envelope4],
            [$envelope5],
            [],
        ]);
        $receiver2 = new DummyReceiver([
            [],
            [$envelope3],
            [],
        ]);
        $receiver3 = new DummyReceiver([
            [$envelope1, $envelope2],
            [],
            [$envelope6],
        ]);

        $bus = $this->createMock(MessageBusInterface::class);

        $processedEnvelopes = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(6));
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, function (WorkerMessageReceivedEvent $event) use (&$processedEnvelopes) {
            $processedEnvelopes[] = $event->getEnvelope();
        });
        $worker = new Worker([$receiver1, $receiver2, $receiver3], $bus, $dispatcher);
        $worker->run();

        // make sure they were processed in the correct order
        $this->assertSame([$envelope1, $envelope2, $envelope3, $envelope4, $envelope5, $envelope6], $processedEnvelopes);
    }

    public function testWorkerLimitQueues()
    {
        $envelope = [new Envelope(new DummyMessage('message1'))];
        $receiver = $this->createMock(QueueReceiverInterface::class);
        $receiver->expects($this->once())
            ->method('getFromQueues')
            ->with(['foo'])
            ->willReturn($envelope)
        ;
        $receiver->expects($this->never())
            ->method('get')
        ;

        $bus = $this->getMockBuilder(MessageBusInterface::class)->getMock();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        $worker = new Worker(['transport' => $receiver], $bus, $dispatcher);
        $worker->run(['queues' => ['foo']]);
    }

    public function testWorkerLimitQueuesUnsupported()
    {
        $receiver1 = $this->createMock(QueueReceiverInterface::class);
        $receiver2 = $this->createMock(ReceiverInterface::class);

        $bus = $this->getMockBuilder(MessageBusInterface::class)->getMock();

        $worker = new Worker(['transport1' => $receiver1, 'transport2' => $receiver2], $bus);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Receiver for "transport2" does not implement "%s".', QueueReceiverInterface::class));
        $worker->run(['queues' => ['foo']]);
    }

    public function testWorkerMessageReceivedEventMutability()
    {
        $envelope = new Envelope(new DummyMessage('Hello'));
        $receiver = new DummyReceiver([[$envelope]]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnArgument(0);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        $stamp = new class() implements StampInterface {
        };
        $listener = function (WorkerMessageReceivedEvent $event) use ($stamp) {
            $event->addStamps($stamp);
        };

        $eventDispatcher->addListener(WorkerMessageReceivedEvent::class, $listener);

        $worker = new Worker([$receiver], $bus, $eventDispatcher);
        $worker->run();

        $envelope = current($receiver->getAcknowledgedEnvelopes());
        $this->assertCount(1, $envelope->all(\get_class($stamp)));
    }

    public function testWorkerShouldLogOnStop()
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Stopping worker.');
        $worker = new Worker([], $bus, new EventDispatcher(), $logger);

        $worker->stop();
    }
}

class DummyReceiver implements ReceiverInterface
{
    private $deliveriesOfEnvelopes;
    private $acknowledgedEnvelopes;
    private $rejectedEnvelopes;
    private $acknowledgeCount = 0;
    private $rejectCount = 0;

    /**
     * @param Envelope[][] $deliveriesOfEnvelopes
     */
    public function __construct(array $deliveriesOfEnvelopes)
    {
        $this->deliveriesOfEnvelopes = $deliveriesOfEnvelopes;
    }

    public function get(): iterable
    {
        $val = array_shift($this->deliveriesOfEnvelopes);

        return $val ?? [];
    }

    public function ack(Envelope $envelope): void
    {
        ++$this->acknowledgeCount;
        $this->acknowledgedEnvelopes[] = $envelope;
    }

    public function reject(Envelope $envelope): void
    {
        ++$this->rejectCount;
        $this->rejectedEnvelopes[] = $envelope;
    }

    public function getAcknowledgeCount(): int
    {
        return $this->acknowledgeCount;
    }

    public function getRejectCount(): int
    {
        return $this->rejectCount;
    }

    public function getAcknowledgedEnvelopes(): array
    {
        return $this->acknowledgedEnvelopes;
    }
}

class DummyQueueReceiver extends DummyReceiver implements QueueReceiverInterface
{
    public function getFromQueues(array $queueNames): iterable
    {
        return $this->get();
    }
}
