<?php

/*
 * This file is part of Monsieur Biz' Coliship plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusColishipPlugin\Exporter;

use MonsieurBiz\SyliusColishipPlugin\Directory\DirectoryInterface;
use MonsieurBiz\SyliusColishipPlugin\Event\ProcessOrderEvent;
use MonsieurBiz\SyliusColishipPlugin\Mapping\MappingInterface;
use MonsieurBiz\SyliusSettingsPlugin\Settings\SettingsInterface;
use SplTempFileObject;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ColishipExporter implements ExporterInterface
{
    /**
     * @var SettingsInterface
     */
    private SettingsInterface $colishipSettings;

    /**
     * @var MappingInterface
     */
    private MappingInterface $fmtMapping;

    /**
     * @var DirectoryInterface
     */
    private DirectoryInterface $fmtDirectory;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * ColishipExporter constructor.
     *
     * @param SettingsInterface $colishipSettings
     * @param MappingInterface $fmtMapping
     * @param DirectoryInterface $fmtDirectory
     * @param OrderRepositoryInterface $orderRepository
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        SettingsInterface $colishipSettings,
        MappingInterface $fmtMapping,
        DirectoryInterface $fmtDirectory,
        OrderRepositoryInterface $orderRepository,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->colishipSettings = $colishipSettings;
        $this->fmtMapping = $fmtMapping;
        $this->fmtDirectory = $fmtDirectory;
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function exportToFile(ChannelInterface $channel): SplTempFileObject
    {
        $file = new SplTempFileObject(-1);
        $ordersToExport = $this->getOrdersToExport($channel);

        if ($this->colishipSettings->getCurrentValue($channel, null, 'debug')) {
            // Header line
            $file->fputcsv($this->getCsvFields($channel), $this->getCsvDelimiter(), $this->getCsvEnclosure(), $this->getCsvEscape());
        }

        foreach ($ordersToExport as $order) {
            $this->processOrder($channel, $order, $file);
        }

        $file->rewind();

        return $file;
    }

    /**
     * @param ChannelInterface $channel
     * @param OrderInterface $order
     * @param SplTempFileObject $file
     */
    private function processOrder(ChannelInterface $channel, OrderInterface $order, SplTempFileObject $file): void
    {
        $data = [];
        $csvFields = $this->getCsvFields($channel);
        foreach ($csvFields as $field) {
            $data[$field] = $this->getFieldValue($field, $order);
        }

        $event = new ProcessOrderEvent($channel, $order, $csvFields, $data);
        $this->eventDispatcher->dispatch($event, ProcessOrderEvent::NAME);

        $file->fputcsv($event->getData(), $this->getCsvDelimiter(), $this->getCsvEnclosure(), $this->getCsvEscape());
    }

    /**
     * @param string $field
     * @param OrderInterface $order
     *
     * @return string
     */
    private function getFieldValue(string $field, OrderInterface $order): string
    {
        return $this->fmtMapping->getValue($field, $order);
    }

    /**
     * @param ChannelInterface $channel
     *
     * @return array
     */
    private function getCsvFields(ChannelInterface $channel): array
    {
        return $this->colishipSettings->getCurrentValue($channel, null, 'exportFields');
    }

    /**
     * @param ChannelInterface $channel
     *
     * @return array
     */
    private function getOrdersToExport(ChannelInterface $channel): array
    {
        $paymentState = $this->colishipSettings->getCurrentValue($channel, null, 'paymentState');
        $shippingState = $this->colishipSettings->getCurrentValue($channel, null, 'shippingState');
        $methodCode = $this->colishipSettings->getCurrentValue($channel, null, 'methodCode');

        return $this->orderRepository
            ->createQueryBuilder('o')
            ->leftJoin('o.shipments', 's')
            ->leftJoin('s.method', 'sm')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.paymentState = :paymentState')
            ->andWhere('o.shippingState = :shippingState')
            ->andWhere('sm.code = :shippingMethod')
            ->setParameter('channel', $channel)
            ->setParameter('paymentState', $paymentState)
            ->setParameter('shippingState', $shippingState)
            ->setParameter('shippingMethod', $methodCode)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return string
     */
    private function getCsvDelimiter(): string
    {
        return ';';
    }

    /**
     * @return string
     */
    private function getCsvEnclosure(): string
    {
        return '"';
    }

    /**
     * @return string
     */
    private function getCsvEscape(): string
    {
        return '"';
    }
}