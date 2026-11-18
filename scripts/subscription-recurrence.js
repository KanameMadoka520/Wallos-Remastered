(function () {
  const dayMilliseconds = 86400000;

  function parseDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const date = new Date(value + "T00:00:00Z");
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
  }

  function getAccountDate(now = new Date()) {
    const timeZone = document.getElementById("subs-form")?.dataset.accountTimezone;
    const parts = new Intl.DateTimeFormat("en", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" })
      .formatToParts(now);
    const part = (key) => parts.find(({ type }) => type === key).value;
    return part("year").padStart(4, "0") + "-" + part("month") + "-" + part("day");
  }

  function isRecurringInterval(cycle, frequency) {
    return [1, 2, 3, 4].includes(cycle) && Number.isInteger(frequency) && frequency > 0;
  }

  function getCycleNumber(start, target, cycle, frequency) {
    if (cycle < 3) {
      return Math.floor((target - start) / dayMilliseconds / (frequency * (cycle === 2 ? 7 : 1)));
    }
    const years = target.getUTCFullYear() - start.getUTCFullYear();
    const periods = cycle === 3 ? years * 12 + target.getUTCMonth() - start.getUTCMonth() : years;
    return Math.floor(periods / frequency);
  }

  function getOccurrenceDate(start, cycle, frequency, index) {
    const steps = frequency * index;
    const date = new Date(start);
    if (cycle < 3) {
      date.setUTCDate(date.getUTCDate() + steps * (cycle === 2 ? 7 : 1));
    } else {
      date.setUTCDate(1);
      date.setUTCMonth(date.getUTCMonth() + steps * (cycle === 4 ? 12 : 1));
      const lastDay = new Date(date);
      lastDay.setUTCMonth(lastDay.getUTCMonth() + 1, 0);
      date.setUTCDate(Math.min(start.getUTCDate(), lastDay.getUTCDate()));
    }
    return date;
  }

  function getNextDate(startValue, cycle, frequency, todayValue = getAccountDate()) {
    const start = parseDate(startValue);
    const today = parseDate(todayValue);
    if (!start || !today) return null;
    if (cycle === 5) return startValue;
    if (!isRecurringInterval(cycle, frequency)) return null;
    if (start > today) return startValue;
    const index = getCycleNumber(start, today, cycle, frequency);
    let date = getOccurrenceDate(start, cycle, frequency, index);
    if (date <= today) date = getOccurrenceDate(start, cycle, frequency, index + 1);
    return date.getUTCFullYear() <= 9999 ? date.toISOString().slice(0, 10) : null;
  }

  function getOccurrenceIndex(subscription, dueValue) {
    const target = parseDate(dueValue);
    if (!target) return null;
    const start = parseDate(subscription.start_date);
    if (!start) return subscription.next_payment === dueValue ? 1 : null;
    const cycle = Number(subscription.cycle ?? 3);
    const frequency = Number(subscription.frequency ?? 1);
    if (target.getTime() === start.getTime()) return 1;
    if (target < start || !isRecurringInterval(cycle, frequency)) return null;
    const index = getCycleNumber(start, target, cycle, frequency);
    return index < 2400 && getOccurrenceDate(start, cycle, frequency, index).getTime() === target.getTime()
      ? index + 1
      : null;
  }

  window.WallosSubscriptionRecurrence = { getAccountDate, getNextDate, getOccurrenceIndex };
})();
