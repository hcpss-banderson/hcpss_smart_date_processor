<?php

namespace Drupal\hcpss_smart_date_processor\Plugin\migrate\process;

use DateInterval;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Drupal\hcpss_smart_date_processor\Model\Override;
use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\smart_date_recur\Entity\SmartDateOverride;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Recurr\Recurrence;
use Recurr\RecurrenceCollection;
use Recurr\Rule;

/**
 * @MigrateProcessPlugin(
 *   id = "hcpss_ical_to_smart_date",
 *   handle_multiples = TRUE
 * )
 */
class IcalToSmartDate extends ProcessPluginBase {
  private function convertStartTime(string $start): DateTimeInterface {
    $local_tz = new DateTimeZone(\Drupal::config('system.date')->get('timezone')['default']);
    if (strlen($start) == 8) {
      return DateTime::createFromFormat('Ymd\THis', "{$start}T000000", $local_tz);
    }
    $date = new DateTime($start, $local_tz);

    return $date;
  }

  private function convertEndTime(string $end): DateTimeInterface {
    $date = $this->convertStartTime($end);
    if (strlen($end) == 8) {
      $date->sub(DateInterval::createFromDateString('1 minute'));
    }

    return $date;
  }

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $entity_type = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];
    $field_name = $destination_property;
    /** @var DateTimeInterface $start */
    $start = $value['start'];
    /** @var DateTimeInterface $end */
    $end = $value['end'];
    $rrule = $value['rrule'] ?? NULL;
    $before = new DateTime($this->configuration['before'] ?? '+1 year');
    $after = new DateTime($this->configuration['after'] ?? '-1 year');
    $existing_event_id = $row->getDestinationProperty('existing');
    $existing_event = $existing_event_id ? \Drupal::entityTypeManager()
      ->getStorage($entity_type)
      ->load($existing_event_id) : NULL;
    /** @var Rule $existing_rule */
    $existing_smart_rule = $existing_event?->get($field_name)->rrule ? SmartDateRule::load($existing_event?->get($field_name)->rrule) : NULL;

    if ($this->isAllDay($start, $end)) {
      $end->sub(new DateInterval('PT1M'));
    }

    $when = [];
    if ($rrule) {
      $rule = new Rule($rrule, $start, $end, 'America/New_York');

      $limit = NULL;
      if ($rule->getUntil()) {
        $limit = 'UNTIL=' . $rule->getUntil()->format('Y-m-d');
      } else if ($rule->getCount()) {
        $limit = 'COUNT=' . $rule->getCount();
      }

      $parameters = NULL;
      if ($rule->getByDay()) {
        $parameters = 'BYDAY=' . implode(',', $rule->getByDay());
      }

      $smart_rule = $existing_smart_rule ?: SmartDateRule::create([]);
      $smart_rule
        ->set('rule', $rule->getString())
        ->set('freq', $rule->getFreqAsText())
        ->set('limit', $limit)
        ->set('parameters', $parameters)
        ->set('unlimited', !$limit)
        ->set('entity_type', $entity_type)
        ->set('bundle', $bundle)
        ->set('field_name', $field_name)
        ->set('start', $start->format('U'))
        ->set('end', $end->format('U'));

      $smart_rule->save();

      /** @var \Recurr\RecurrenceCollection $instances_objs */
      $instances = $smart_rule->makeRuleInstances($before->getTimestamp(), $after->getTimestamp());

      $existing_overrides = $smart_rule->getRuleOverrides();
      $new_override_params = $this->extractOverrideParams($value, $instances);

      if (!empty($new_override_params)) {
        foreach ($new_override_params as $index => $params) {
          if (!empty($existing_overrides[$index])) {
            $existing_overrides[$index]
              ->set('value', $params['value'])
              ->set('end_value', $params['end_value'])
              ->set('duration', $params['duration'])
              ->save();
            unset($existing_overrides[$index]);
          } else {
            $params['rrule'] = $smart_rule->id();
            SmartDateOverride::create($params)->save();
          }
        }
      }

      if (!empty($existing_overrides)) {
        // The old overrides.
        foreach ($existing_overrides as $override) {
          $override->delete();
        }
      }

      foreach ($smart_rule->getRuleInstances($before->getTimestamp(), $after->getTimestamp()) as $index => $instance) {
        $when[] = [
          'value' => $instance['value'],
          'end_value' => $instance['end_value'],
          'duration' => round(($instance['end_value'] - $instance['value']) / 60),
          'rrule' => $smart_rule->id(),
          'rrule_index' => $index,
          'timezone' => '',
        ];
      }
    } else {
      if ($existing_smart_rule) {
        $existing_smart_rule->delete();
      }

      $when[] = [
        'value' => $start->getTimestamp(),
        'end_value' => $end->getTimestamp(),
        'duration' => round(($end->getTimestamp() - $start->getTimestamp()) / 60),
        'rrule' => NULL,
        'rrule_index' => NULL,
        'timezone' => '',
      ];
    }

    return $when;
  }

  private function extractOverrideParams(array $value, RecurrenceCollection $instances): array {
    $new_override_params = [];
    if (!empty($value['exdate'])) {
      $new_override_params += $this->getOverrideParamsFromOverrides(array_map(function ($datestring) {
        return Override::createFromExdate($datestring);
      }, $value['exdate']), $instances);
    }
    if (!empty($value['recurrences'])) {
      $new_override_params += $this->getOverrideParamsFromOverrides(array_map(function ($recurrence) {
        return Override::createFromEventArray($recurrence);
      }, $value['recurrences']), $instances);
    }
    return $new_override_params;
  }

  /**
   * @param \Drupal\hcpss_event_importer\Model\Override[] $overrides
   * @param RecurrenceCollection $instances
   * @return array
   */
  private function getOverrideParamsFromOverrides(array $overrides, RecurrenceCollection $instances): array {
    $params = [];
    foreach ($overrides as $override) {
      $instance = $instances->findFirst(function($key, Recurrence $i) use ($override) {
        return $i->getStart()->getTimestamp() == $override->getId()->getTimestamp();
      });

      if ($instance) {
        $params[$instance->getIndex()] = [
          'rrule_index' => $instance->getIndex(),
          'value' => $override->getStart()?->getTimestamp(),
          'end_value' => $override->getEnd()?->getTimestamp(),
          'duration' => $override->getInterval()?->format('%s'),
        ];
      }
    }

    return $params;
  }

  /**
   * Is this am all day event?
   *
   * @param \DateTimeInterface $start
   * @param \DateTimeInterface $end
   * @return bool
   */
  private function isAllDay(DateTimeInterface $start, DateTimeInterface $end): bool {
    if (
      $start->format('His') == '000000' &&
      $end->format('His') == '000000'
    ) {
      return TRUE;
    }

    return FALSE;
  }
}
