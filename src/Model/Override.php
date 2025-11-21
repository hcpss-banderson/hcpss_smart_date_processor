<?php

namespace Drupal\hcpss_smart_date_processor\Model;

use DateInterval;
use DateTimeImmutable;

class Override {
  private ?DateInterval $interval;
  public function __construct(
    private readonly DateTimeImmutable $id,
    private readonly ?DateTimeImmutable $start = null,
    private readonly ?DateTimeImmutable $end = null,
  ) {
    $this->interval = ($start && $this->end) ? $start->diff($end) : null;
  }

  public function getInterval(): ?DateInterval {
    return $this->interval;
  }

  public function getId(): DateTimeImmutable {
    return $this->id;
  }

  public function getStart(): ?DateTimeImmutable {
    return $this->start;
  }

  public function getEnd(): ?DateTimeImmutable {
    return $this->end;
  }

  public static function createFromExdate(string $exdate): Override {
    return new Override(DateTimeImmutable::createFromFormat('Ymd\THis', $exdate));sr
  }

  public static function createFromEventArray(array $recurrence): Override {
    return new Override(
      DateTimeImmutable::createFromMutable($recurrence['recurrence_id']),
      DateTimeImmutable::createFromMutable($recurrence['time']['start']),
      DateTimeImmutable::createFromMutable($recurrence['time']['end'])
    );
  }
}
