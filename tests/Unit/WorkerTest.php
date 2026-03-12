<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\ChannelDelivery\Worker\EmailWorkerRunner;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WorkerTest extends TestCase
{
    public function testWorkerProcessesBatchWithoutLooping(): void
    {
        // Arrange
        // We bypass final class constraint by mocking its dependencies
        // But since EmailQueueWorker is from a different package and final,
        // we can just use an anonymous class that implements a similar interface
        // if we change the typehint, OR we can just mock the logger and test the runner

        // Since EmailWorkerRunner requires exactly EmailQueueWorker,
        // and EmailQueueWorker is final, we cannot easily mock it.
        // Instead, we will construct a real EmailQueueWorker with mocked dependencies.
        $pdoMock = $this->createMock(\PDO::class);
        $cfReflection = new \ReflectionClass(\Maatify\Crypto\DX\CryptoProvider::class);
        $cryptoProviderReal = $cfReflection->newInstanceWithoutConstructor();

        $rendererMock = $this->createMock(\Maatify\EmailDelivery\Renderer\EmailRendererInterface::class);
        $transportMock = $this->createMock(\Maatify\EmailDelivery\Transport\EmailTransportInterface::class);
        $cryptoContextMock = $this->createMock(\Maatify\Crypto\Contract\CryptoContextProviderInterface::class);
        $workerLoggerMock = $this->createMock(LoggerInterface::class);

        $statementMock = $this->createMock(\PDOStatement::class);
        $statementMock->method('fetchAll')->willReturn([]);
        $pdoMock->method('prepare')->willReturn($statementMock);
        $pdoMock->method('beginTransaction')->willReturn(true);

        $workerMock = new EmailQueueWorker(
            $pdoMock,
            $cryptoProviderReal,
            $rendererMock,
            $transportMock,
            $cryptoContextMock,
            $workerLoggerMock
        );

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->exactly(3)) // start, processed, finished
            ->method('info');

        $runner = new EmailWorkerRunner($workerMock, $loggerMock);

        // Act
        $runner->run(10, false, 0);

        // Assert
        $this->assertTrue(true); // Reached end of test without errors
    }

    public function testWorkerLogsExceptionsAndContinues(): void
    {
        // Arrange
        $pdoMock = $this->createMock(\PDO::class);

        $cfReflection = new \ReflectionClass(\Maatify\Crypto\DX\CryptoProvider::class);
        $cryptoProviderReal = $cfReflection->newInstanceWithoutConstructor();

        $rendererMock = $this->createMock(\Maatify\EmailDelivery\Renderer\EmailRendererInterface::class);
        $transportMock = $this->createMock(\Maatify\EmailDelivery\Transport\EmailTransportInterface::class);
        $cryptoContextMock = $this->createMock(\Maatify\Crypto\Contract\CryptoContextProviderInterface::class);
        $workerLoggerMock = $this->createMock(LoggerInterface::class);

        // Force an exception during processBatch
        $pdoMock->method('beginTransaction')->willThrowException(new \RuntimeException('DB Connection failed'));

        $workerMock = new EmailQueueWorker(
            $pdoMock,
            $cryptoProviderReal,
            $rendererMock,
            $transportMock,
            $cryptoContextMock,
            $workerLoggerMock
        );

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Worker batch failed'));

        $runner = new EmailWorkerRunner($workerMock, $loggerMock);

        // Act
        $runner->run(10, false, 0);

        // Assert
        // The exception should be caught and logged, not thrown up.
    }
}
