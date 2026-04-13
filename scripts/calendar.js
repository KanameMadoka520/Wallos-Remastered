function nextMonth(currentMonth, currentYear) {
  let nextMonth = currentMonth + 1;
  let nextYear = currentYear;
  if (nextMonth > 12) {
    nextMonth = 1;
    nextYear += 1;
  }
  window.location.href = `calendar.php?month=${nextMonth}&year=${nextYear}`;
}

function prevMonth(currentMonth, currentYear) {
  let prevMonth = currentMonth - 1;
  let prevYear = currentYear;
  if (prevMonth < 1) {
    prevMonth = 12;
    prevYear -= 1;
  }
  window.location.href = `calendar.php?month=${prevMonth}&year=${prevYear}`;
}

function currentMoth() {
    window.location.href = `calendar.php`;
}

function syncCalendarJumpControls() {
  const container = document.getElementById('calendar-nav-jump');
  const yearSelect = document.getElementById('calendarYearSelect');
  const monthSelect = document.getElementById('calendarMonthSelect');

  if (!container || !yearSelect || !monthSelect) {
    return;
  }

  const currentYear = Number(container.dataset.currentYear || 0);
  const currentMonth = Number(container.dataset.currentMonth || 1);
  const selectedYear = Number(yearSelect.value || currentYear);

  Array.from(monthSelect.options).forEach((option) => {
    const monthValue = Number(option.value || 0);
    option.disabled = selectedYear === currentYear && monthValue < currentMonth;
  });

  if (monthSelect.selectedOptions.length > 0 && monthSelect.selectedOptions[0].disabled) {
    monthSelect.value = String(currentMonth);
  }
}

function goToCalendarDate() {
  const yearSelect = document.getElementById('calendarYearSelect');
  const monthSelect = document.getElementById('calendarMonthSelect');

  if (!yearSelect || !monthSelect) {
    return;
  }

  const selectedYear = Number(yearSelect.value || new Date().getFullYear());
  const selectedMonth = Number(monthSelect.value || new Date().getMonth() + 1);
  window.location.href = `calendar.php?month=${selectedMonth}&year=${selectedYear}`;
}

function closeSubscriptionModal() {
    const modal = document.getElementById('subscriptionModal');
    modal.classList.remove('is-open');
}

function openSubscriptionModal(subscriptionId) {
    const modal = document.getElementById('subscriptionModal');
    const modalContent = document.getElementById('subscriptionModalContent');

    modalContent.innerHTML = '';

    fetch('endpoints/subscription/getcalendar.php', {
        method: 'POST',
        body: JSON.stringify({id: subscriptionId}),
        headers: {
          'Content-Type': 'application/json'
        }
      })
      .then(response => response.json())
      .then(data => {
        if (data.success && data.data) {
          const subscription = data.data;
          const html = `
            <div class="modal-header">
                <h3>${subscription.name}</h3>
                <span class="fa-solid fa-xmark close-modal" onclick="closeSubscriptionModal()"></span>
            </div>
            <div class="modal-body">
                ${subscription.logo ? `<div class="subscription-logo">
                <img src="images/uploads/logos/${subscription.logo}" alt="${subscription.name}">
                </div>` : ''}
                <div class="subscription-info">
                ${subscription.price ? `<p><strong>${translate('price')}:</strong> ${subscription.currency}${subscription.price}</p>` : ''}
                ${subscription.category ? `<p><strong>${translate('category')}:</strong> ${subscription.category}</p>` : ''}
                ${subscription.payer_user ? `<p><strong>${translate('paid_by')}:</strong> ${subscription.payer_user}</p>` : ''}
                ${subscription.payment_method ? `<p><strong>${translate('payment_method')}:</strong> ${subscription.payment_method}</p>` : ''}
                ${subscription.notes_html ? `<div class="calendar-subscription-notes"><strong>${translate('notes')}:</strong><div class="subscription-markdown">${subscription.notes_html}</div></div>` : ''}
                </div>
            </div>
            <div class="modal-footer">
                <button class="button tiny" onclick="exportCalendar(${subscription.id})">${translate('export')}</button>
            </div>`;
          modalContent.innerHTML = html;
          modal.classList.add('is-open');
        } else {
          console.error(data.message);
        }
      })
      .catch(error => console.error('Error:', error));
}

function decodeHtmlEntities(str) {
  const txt = document.createElement('textarea');
  txt.innerHTML = str;
  return txt.value;
}

function exportCalendar(subscriptionId) {
  fetch('endpoints/subscription/exportcalendar.php', {
    method: 'POST',
    body: JSON.stringify({id: subscriptionId}),
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    }
  })
  .then(response => response.json())
  .then(data => {
    if (data.success && data.ics) {
      const blob = new Blob([data.ics], {type: 'text/calendar'});
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      // Use the subscription name for the file name, replacing any characters that are invalid in file names
      a.download = `${decodeHtmlEntities(data.name).replace(/[\/\\:*?"<>|]/g, '_').toLowerCase()}.ics`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
    } else {
      showErrorMessage(data.message);
    }
  })
  .catch(error => console.error('Error:', error));
}

function showExportPopup() {
  const host = window.location.href;
  const apiPath = "api/subscriptions/get_ical_feed.php";
  const apiKey = document.getElementById('apiKey').value;
  const queryParams = `?api_key=${apiKey}`;
  const fullUrl = host.replace('calendar.php', apiPath) + queryParams;
  document.getElementById('iCalendarUrl').value = fullUrl;
  document.getElementById('subscriptions_calendar').classList.add('is-open');
}

function closePopup() {
  document.getElementById('subscriptions_calendar').classList.remove('is-open');
}

function copyToClipboard() {
  const urlField = document.getElementById('iCalendarUrl');
  urlField.select();
  urlField.setSelectionRange(0, 99999); // For mobile devices
  navigator.clipboard.writeText(urlField.value)
      .then(() => {
          showSuccessMessage(translate('copied_to_clipboard'));
      })
      .catch(() => {
          showErrorMessage(translate('unknown_error'));
      });
}

document.addEventListener('DOMContentLoaded', function () {
  syncCalendarJumpControls();
});
