<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_ledger\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sm_ledger\Form\SmLedgerSettingsForm;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the SM Ledger settings form.
 */
final class SmLedgerSettingsFormUnitTest extends UnitTestCase {

  /**
   * Tests validation rejects heartbeat intervals at or above stale threshold.
   */
  public function testValidateRejectsHeartbeatAtOrAboveStaleThreshold(): void {
    $form = new SmLedgerSettingsForm($this->createReadonlyConfigFactory([
      'retention.completed_days' => 30,
      'retention.abandoned_days' => 30,
      'retention.failed_days' => 0,
      'dedupe.ttl_seconds' => 86400,
      'recovery.stale_claim_threshold_seconds' => 3600,
      'recovery.heartbeat_interval_seconds' => 30,
      'dispatch.deadlock_retry_attempts' => 3,
      'dispatch.deadlock_retry_delay_ms' => 100,
    ]), $this->createMock(TypedConfigManagerInterface::class));
    $form->setStringTranslation($this->getStringTranslationStub());

    $formState = (new FormState())
      ->setValue('completed_days', 30)
      ->setValue('abandoned_days', 30)
      ->setValue('failed_days', 0)
      ->setValue('ttl_seconds', 86400)
      ->setValue('stale_claim_threshold_seconds', 60)
      ->setValue('heartbeat_interval_seconds', 60)
      ->setValue('deadlock_retry_attempts', 3)
      ->setValue('deadlock_retry_delay_ms', 100);

    $formArray = [];
    $form->validateForm($formArray, $formState);

    static::assertArrayHasKey('heartbeat_interval_seconds', $formState->getErrors());
  }

  /**
   * Tests submit stores ledger settings.
   */
  public function testSubmitPersistsSettings(): void {
    $saved = [];
    $form = new SmLedgerSettingsForm($this->createWritableConfigFactory([
      'retention.completed_days' => 30,
      'retention.abandoned_days' => 30,
      'retention.failed_days' => 0,
      'dedupe.ttl_seconds' => 86400,
      'recovery.stale_claim_threshold_seconds' => 3600,
      'recovery.heartbeat_interval_seconds' => 30,
      'dispatch.deadlock_retry_attempts' => 3,
      'dispatch.deadlock_retry_delay_ms' => 100,
    ], $saved), $this->createMock(TypedConfigManagerInterface::class));
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->createMock(MessengerInterface::class));

    $formState = (new FormState())
      ->setValue('completed_days', 14)
      ->setValue('abandoned_days', 21)
      ->setValue('failed_days', 7)
      ->setValue('ttl_seconds', 7200)
      ->setValue('stale_claim_threshold_seconds', 1800)
      ->setValue('heartbeat_interval_seconds', 120)
      ->setValue('deadlock_retry_attempts', 4)
      ->setValue('deadlock_retry_delay_ms', 250);

    $formArray = [];
    $form->submitForm($formArray, $formState);

    static::assertSame(14, $saved['retention.completed_days']);
    static::assertSame(21, $saved['retention.abandoned_days']);
    static::assertSame(7, $saved['retention.failed_days']);
    static::assertSame(7200, $saved['dedupe.ttl_seconds']);
    static::assertSame(1800, $saved['recovery.stale_claim_threshold_seconds']);
    static::assertSame(120, $saved['recovery.heartbeat_interval_seconds']);
    static::assertSame(4, $saved['dispatch.deadlock_retry_attempts']);
    static::assertSame(250, $saved['dispatch.deadlock_retry_delay_ms']);
  }

  /**
   * Creates a readonly config factory.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by name.
   */
  private function createReadonlyConfigFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('sm_ledger.settings')
      ->willReturn($config);

    return $factory;
  }

  /**
   * Creates a writable config factory.
   *
   * @param array<string, mixed> $values
   *   Initial config values.
   * @param array<string, mixed> $saved
   *   Collected saved values.
   */
  private function createWritableConfigFactory(array $values, array &$saved): ConfigFactoryInterface {
    $factory = $this->createReadonlyConfigFactory($values);
    $editable = $this->createMock(Config::class);
    $editable->method('set')
      ->willReturnCallback(function (string $key, mixed $value) use (&$saved, $editable): Config {
        $saved[$key] = $value;
        return $editable;
      });
    $editable->expects($this->once())->method('save');

    $factory->method('getEditable')
      ->with('sm_ledger.settings')
      ->willReturn($editable);

    return $factory;
  }

}
