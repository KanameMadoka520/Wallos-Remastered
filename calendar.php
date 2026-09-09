<?php
require_once 'includes/header.php';
require_once 'includes/subscription_trash.php';
require_once 'includes/calendar_calculations.php';
require_once 'includes/currency_rates.php';

$calendarJsVersion = $version . '.' . @filemtime(__DIR__ . '/scripts/calendar.js');

function getPriceConverted($price, $currency, $database, $userId)
{
  return wallos_convert_price($price, $currency, $database, $userId);
}

// Get budget from user table
$query = "SELECT budget FROM user WHERE id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$budget = $row['budget'] ?? 0;

$currentMonth = date('m');
$currentYear = date('Y');
$calendarSelectableYearEnd = max(((int) $currentYear) + 15, ((int) $currentYear) + 5);
$sameAsCurrent = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['month']) && isset($_GET['year'])) {
  // Don't allow viewing past months
  $selectedMonth = str_pad($_GET['month'], 2, '0', STR_PAD_LEFT);
  $selectedYear = $_GET['year'];

  $selectedTimestamp = strtotime($selectedYear . '-' . $selectedMonth . '-01');
  $currentTimestamp = strtotime($currentYear . '-' . $currentMonth . '-01');

  if ($selectedTimestamp < $currentTimestamp) {
    $calendarMonth = $currentMonth;
    $calendarYear = $currentYear;
  } else {
    $calendarMonth = $selectedMonth;
    $calendarYear = $selectedYear;
  }

  if ($calendarMonth == $currentMonth && $calendarYear == $currentYear) {
    $sameAsCurrent = true;
  }
} else {
  $calendarMonth = $currentMonth;
  $calendarYear = $currentYear;
  $sameAsCurrent = true;
}

$currenciesInUse = [];
$numberOfSubscriptionsToPayThisMonth = 0;
$totalCostThisMonth = 0;
$amountDueThisMonth = 0;

$query = "SELECT * FROM subscriptions WHERE user_id = :user_id AND lifecycle_status = :lifecycle_status AND inactive = 0";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
$result = $stmt->execute();
$subscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $subscriptions[] = $row;
  $currenciesInUse[] = $row['currency_id'];
}

$currenciesInUse = array_unique($currenciesInUse);
$usesMultipleCurrencies = count($currenciesInUse) > 1;

$showCantConverErrorMessage = false;
if ($usesMultipleCurrencies) {
  $query = "SELECT api_key FROM fixer WHERE user_id = :userId";
  $stmt = $db->prepare($query);
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();
  if ($result->fetchArray(SQLITE3_ASSOC) === false) {
    $showCantConverErrorMessage = true;
  }
}

// Get code of main currency to display on statistics
$query = "SELECT c.code
          FROM currencies c
          INNER JOIN user u ON c.id = u.main_currency
          WHERE u.id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$code = $row['code'];

$yearsToLoad = $calendarYear - $currentYear + 1;
$calendarSelectableYearEnd = max($calendarSelectableYearEnd, ((int) $calendarYear) + 5);
$calendarSelectableYears = range((int) $currentYear, $calendarSelectableYearEnd);
$weekStartsSunday = !empty($settings['week_starts_sunday']);
$calendarWeekDays = wallos_calendar_get_week_days($weekStartsSunday);
?>

<section class="contain">
  <?php
  if ($showCantConverErrorMessage) {
    ?>
    <div class="error-box">
      <div class="error-message">
        <i class="fa-solid fa-exclamation-circle"></i>
        <?= translate('cant_convert_currency', $i18n) ?>
      </div>
    </div>
    <?php
  }
  ?>
  <div class="split-header">
    <h2>
    <?= translate('calendar', $i18n) ?>
      <button class="button export-ical" onClick="showExportPopup()" title="<?= translate('export_icalendar', $i18n) ?>">
        <?php require_once 'images/siteicons/svg/export_ical.php'; ?>
      </button>
    </h2>
    <div id="subscriptions_calendar" class="subscription-modal">
        <div class="modal-header">
            <h3><?= translate('export_icalendar', $i18n) ?></h3>
            <span class="fa-solid fa-xmark close-modal" onclick="closePopup()"></span>
        </div>
        <div class="form-group-inline">
            <input id="iCalendarUrl" type="text" value="" readonly>
            <input type="hidden" id="apiKey" value="<?= $screenshotPrivacyEnabled ? '' : htmlspecialchars((string) ($userData['api_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <button onclick="copyToClipboard()" class="button tiny"> <?= translate('copy_to_clipboard', $i18n) ?> </button>
        </div>
    </div>

    <div class="calendar-nav">
      <?php
      if (!$sameAsCurrent) {
        ?>
        <button class="button secondary-button tiny" onClick="currentMoth()" title="<?= translate('reset', $i18n) ?>"><i
            class="fa-solid fa-calendar-day"></i></button>
        <button class="button tiny" id="prev" onclick="prevMonth(<?= $calendarMonth ?>, <?= $calendarYear ?>)"><i
            class="fa-solid fa-chevron-left"></i></button>
        <?php
      }
      ?>
      <div class="calendar-nav-current" id="calendar-nav-jump" data-current-year="<?= (int) $currentYear ?>"
        data-current-month="<?= (int) $currentMonth ?>">
        <select id="calendarMonthSelect" class="calendar-nav-inline-select" onchange="goToCalendarDate()">
          <?php for ($monthOption = 1; $monthOption <= 12; $monthOption++): ?>
            <?php
            $monthValue = str_pad((string) $monthOption, 2, '0', STR_PAD_LEFT);
            $isPastMonth = ((int) $calendarYear === (int) $currentYear) && ($monthOption < (int) $currentMonth);
            ?>
            <option value="<?= $monthOption ?>" <?= (int) $calendarMonth === $monthOption ? 'selected' : '' ?>
              <?= $isPastMonth ? 'disabled' : '' ?>>
              <?= translate('month-' . $monthValue, $i18n) ?>
            </option>
          <?php endfor; ?>
        </select>
        <select id="calendarYearSelect" class="calendar-nav-inline-select" onchange="syncCalendarJumpControls(true)">
          <?php foreach ($calendarSelectableYears as $yearOption): ?>
            <option value="<?= (int) $yearOption ?>" <?= (int) $calendarYear === (int) $yearOption ? 'selected' : '' ?>>
              <?= (int) $yearOption ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="button tiny" id="next" onclick="nextMonth(<?= $calendarMonth ?>, <?= $calendarYear ?>)"><i
          class="fa-solid fa-chevron-right"></i></button>
    </div>
  </div>
  <div>
    <?php
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $calendarMonth, $calendarYear);
    $firstDay = mktime(0, 0, 0, $calendarMonth, 1, $calendarYear);
    $firstDayOfWeek = wallos_calendar_get_first_day_offset($firstDay, $weekStartsSunday);
    $today = strtotime(date('Y-m-d'));
    $todayDay = (int) date('j');
    $todayMonth = date('m');
    $todayYear = date('Y');

    // Project each subscription once, then render the existing custom grid.
    // This keeps totals and displayed events on the same date calculation.
    $paymentsByDay = [];
    $calendarPrivacyEnabled = function_exists('wallos_screenshot_privacy_enabled')
      && wallos_screenshot_privacy_enabled($settings);
    $calendarPrivacySeed = $calendarPrivacyEnabled ? wallos_screenshot_privacy_seed() : '';
    foreach ($subscriptions as $subscription) {
      $nextPaymentTimestamp = wallos_calendar_parse_date($subscription['next_payment'] ?? '');
      $paymentDates = wallos_calendar_get_payment_dates(
        $subscription,
        $calendarYear,
        $calendarMonth,
        $yearsToLoad
      );

      foreach ($paymentDates as $paymentDate) {
        $dayNumber = (int) date('j', $paymentDate);
        $displaySubscription = $calendarPrivacyEnabled
          ? wallos_screenshot_privacy_mask_subscription(
              $subscription,
              $calendarPrivacySeed,
              $lang,
              'calendar'
            )
          : $subscription;
        $isCurrentNextPayment = $nextPaymentTimestamp !== false
          && date('Y-m-d', $paymentDate) === date('Y-m-d', $nextPaymentTimestamp);
        if (!$isCurrentNextPayment
          && $nextPaymentTimestamp !== false
          && $paymentDate < $nextPaymentTimestamp) {
          // The projection intentionally includes older occurrences in the
          // selected month. Once next_payment has advanced past one, it must
          // not be presented as an overdue renewal.
          $countdown = ['state' => 'completed', 'days' => 0];
        } elseif (!$isCurrentNextPayment
          && $nextPaymentTimestamp !== false
          && $nextPaymentTimestamp < $today) {
          // Do not imply a later forecast can be handled while the real next
          // payment is still overdue.
          $countdown = ['state' => 'blocked', 'days' => 0];
        } else {
          $countdown = wallos_calendar_get_renewal_countdown($paymentDate, $today);
        }
        $isDueThisMonth = $sameAsCurrent
          && $isCurrentNextPayment
          && date('Y-m', $nextPaymentTimestamp) === sprintf('%04d-%02d', $calendarYear, $calendarMonth);
        $paymentsByDay[$dayNumber][] = [
          'subscription' => $displaySubscription,
          'countdown' => $countdown,
          'is_due_this_month' => $isDueThisMonth,
        ];

        $convertedPrice = getPriceConverted(
          $subscription['price'],
          $subscription['currency_id'],
          $db,
          $userId
        );
        $totalCostThisMonth += $convertedPrice;
        $numberOfSubscriptionsToPayThisMonth++;
        // A payment due today is still due; only older dates are paid.
        if (wallos_calendar_is_due($paymentDate, $today)) {
          $amountDueThisMonth += $convertedPrice;
        }
      }
    }
    ?>

    <div class="calendar">
      <div class="calendar-header">
        <?php foreach ($calendarWeekDays as $calendarWeekDay): ?>
          <div class="calendar-cell"><?= translate($calendarWeekDay['key'], $i18n) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="calendar-body">
        <div class="week calendar-row">
          <?php
          $dayOfWeek = 0;
          for ($i = 0; $i < $firstDayOfWeek; $i++) {
            ?>
            <div class="calendar-cell empty">
              <div class="calendar-cell-header">
                <span class="day">&nbsp;</span>
              </div>
              <div class="calendar-cell-content"></div>
            </div>
            <?php
            $dayOfWeek++;
          }
          for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($dayOfWeek > 0 && $dayOfWeek % 7 == 0) {
              ?>
            </div>
            <div class="week calendar-row">
              <?php
            }
            $dayClass = ($day == $todayDay && $calendarMonth == $todayMonth && $calendarYear == $todayYear) ? 'today' : '';
            ?>
            <?php
            $dayPayments = $paymentsByDay[$day] ?? [];
            $hasDueThisMonth = false;
            foreach ($dayPayments as $dayPayment) {
              if (!empty($dayPayment['is_due_this_month'])) {
                $hasDueThisMonth = true;
                break;
              }
            }
            $dayClass .= $hasDueThisMonth ? ' calendar-cell--has-current-due' : '';
            ?>
            <div class="calendar-cell <?= $dayClass ?>">
              <div class="calendar-cell-header">
                <span class="day"><?= $day ?></span>
              </div>
              <div class="calendar-cell-content">
                <?php foreach ($dayPayments as $payment): ?>
                  <?php
                  $subscription = $payment['subscription'];
                  $countdown = $payment['countdown'];
                  $countdownState = is_array($countdown) ? (string) ($countdown['state'] ?? 'upcoming') : 'upcoming';
                  $countdownDays = is_array($countdown) ? (int) ($countdown['days'] ?? 0) : 0;
                  $countdownLabel = $countdownState === 'completed'
                    ? translate('calendar_renewal_completed', $i18n)
                    : ($countdownState === 'blocked'
                      ? translate('calendar_renewal_handle_previous_first', $i18n)
                      : ($countdownState === 'overdue'
                        ? sprintf(translate('calendar_renewal_overdue_days', $i18n), $countdownDays)
                        : ($countdownState === 'today'
                          ? translate('calendar_renewal_due_today', $i18n)
                          : sprintf(translate('calendar_renewal_days_until', $i18n), $countdownDays))));
                  $titleClasses = 'calendar-subscription-title calendar-subscription-title--' . $countdownState;
                  if (!empty($payment['is_due_this_month'])) {
                    $titleClasses .= ' calendar-subscription-title--current-due';
                  }
                  ?>
                  <div class="<?= $titleClasses ?>" data-subscription-id="<?= (int) $subscription['id'] ?>"
                    aria-label="<?= htmlspecialchars($subscription['name'] . ' · ' . $countdownLabel, ENT_QUOTES, 'UTF-8') ?>"
                    onClick="openSubscriptionModal(<?= (int) $subscription['id'] ?>)">
                    <span class="calendar-subscription-name"><?= htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="calendar-subscription-countdown"><?= htmlspecialchars($countdownLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($payment['is_due_this_month'])): ?>
                      <span class="calendar-subscription-renewal-flag"><?= translate('calendar_renewal_due_this_month', $i18n) ?></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php
            $dayOfWeek++;
          }
          while ($dayOfWeek % 7 != 0) {
            ?>
            <div class="calendar-cell empty">
              <div class="calendar-cell-header">
                <span class="day">&nbsp;</span>
              </div>
              <div class="calendar-cell-content"></div>
            </div>
            <?php
            $dayOfWeek++;
          }
          ?>
        </div>
      </div>
    </div>

    <?php
      if ($budget > 0 && $totalCostThisMonth > $budget) {
        $overBudgetAmount = $totalCostThisMonth - $budget;
        $overBudgetAmount = CurrencyFormatter::format($overBudgetAmount, $code);
        ?>
          <div class="over-budget">
            <i class="fa-solid fa-exclamation-triangle"></i>
            <?= translate('over_budget_warning', $i18n) ?>  (<?= $overBudgetAmount ?>)
          </div>
        <?php
      }
    ?>    

    <div class="calendar-monthly-stats">
      <div class="calendar-monthly-stats-header">
        <h3><?= translate("stats", $i18n) ?></h3>
      </div>
      <div class="statistics">
        <div class="statistic">
          <span>
            <?= $numberOfSubscriptionsToPayThisMonth ?></span>
          <div class="title"><?= translate("active_subscriptions", $i18n) ?></div>
        </div>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($totalCostThisMonth, $code) ?></span>
          <div class="title"><?= translate("total_cost", $i18n) ?></div>
        </div>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($amountDueThisMonth, $code) ?></span>
          <div class="title"><?= translate("amount_due", $i18n) ?></div>
        </div>
      </div>
    </div>

</section>

<div id="subscriptionModal" class="subscription-modal">
  <div class="modal-content">
    <div id="subscriptionModalContent"></div>
  </div>
</div>

<script defer src="scripts/calendar.js?v=<?= $calendarJsVersion ?>"></script>
<?php
require_once 'includes/footer.php';
?>
