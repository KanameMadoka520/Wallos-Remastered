(() => {
  const root = document.getElementById('webpush-settings');
  if (!root) return;
  const status = document.getElementById('webpush-status');
  const enabled = document.getElementById('webpush-enabled');
  const details = document.getElementById('webpush-details');
  const subscribe = document.getElementById('webpush-subscribe');
  const name = document.getElementById('webpush-device-name');
  const list = document.getElementById('webpush-devices');
  const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  let state;
  const api = async data => {
    const result = await window.WallosApi.postJson('endpoints/notifications/webpush.php', data);
    if (!result.success) throw new Error(result.message || translate('error'));
    return result;
  };
  function fail(error) {
    status.textContent = error.message || translate('error');
    showErrorMessage(status.textContent);
  }
  async function action(data) {
    try { render(await api(data)); } catch (error) { fail(error); }
  }
  function render(data) {
    state = data;
    enabled.checked = Number(data.settings.enabled) === 1;
    details.checked = Number(data.settings.show_details) === 1;
    list.replaceChildren();
    data.devices.forEach(device => {
      const row = document.createElement('li');
      row.className = 'webpush-device';
      const label = document.createElement('span');
      label.className = 'webpush-device-label';
      label.textContent = device.device_name + (device.current ? ' (' + translate('webpush_this_device') + ')' : '');
      const actions = document.createElement('div');
      actions.className = 'webpush-device-actions';
      row.append(label, actions);
      [['webpush_test', 'test'], ['webpush_rename', 'rename'], ['webpush_revoke', 'remove']].forEach(([key, operation]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button ' + (operation === 'remove' ? 'warning-button' : 'secondary-button') + ' tiny';
        button.textContent = translate(key);
        button.addEventListener('click', async () => {
          const data = {action: operation, id: Number(device.id)};
          if (operation === 'rename') {
            const deviceName = window.prompt(translate('webpush_device_name'), device.device_name);
            if (deviceName === null) return;
            data.device_name = deviceName;
          }
          button.disabled = true;
          try {
            // Revoke on the server first. Browser failure cannot leave a remote
            // device bound after its UI has reported revocation.
            render(await api(data));
            if (operation === 'remove' && device.current && supported) {
              const registration = await navigator.serviceWorker.ready;
              const subscription = await registration.pushManager.getSubscription();
              if (subscription) await subscription.unsubscribe();
            }
            status.textContent = translate(operation === 'test' ? 'webpush_test_accepted' : 'success');
          } catch (error) { fail(error); } finally { button.disabled = false; }
        });
        actions.append(button);
      });
      list.append(row);
    });
    if (!data.devices.length) {
      const item = document.createElement('li');
      item.className = 'webpush-empty';
      item.textContent = translate('webpush_no_devices');
      list.append(item);
    }
    status.textContent = supported ? translate('webpush_ready') : translate('webpush_unsupported');
  }
  subscribe.disabled = !supported;
  document.getElementById('webpush-save').addEventListener('click', () => action({action:'save', enabled:enabled.checked ? 1 : 0, show_details:details.checked ? 1 : 0}));
  subscribe.addEventListener('click', async () => {
    subscribe.disabled = true;
    let created = false;
    let subscription;
    try {
      // Request permission synchronously from this user gesture (required on iOS).
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error(translate('webpush_permission_denied'));
      if (!state) state = await api({action:'status'});
      const registration = await navigator.serviceWorker.ready;
      subscription = await registration.pushManager.getSubscription();
      const hasBinding = state.devices.some(device => device.current);
      if (subscription && !hasBinding) { await subscription.unsubscribe(); subscription = null; }
      if (!subscription) {
        const base64 = state.public_key.replace(/-/g, '+').replace(/_/g, '/');
        const key = Uint8Array.from(atob(base64 + '='.repeat((4-base64.length%4)%4)), char => char.charCodeAt(0));
        subscription = await registration.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:key});
        created = true;
      }
      const fallbackName = /iPhone|iPad/.test(navigator.userAgent) ? 'iPhone / iPad' : 'Browser';
      render(await api({action:'register', device_name:name.value.trim() || fallbackName, subscription:subscription.toJSON()}));
      showSuccessMessage(translate('webpush_device_enabled'));
    } catch (error) {
      if (created && subscription) await subscription.unsubscribe().catch(() => {});
      fail(error);
    } finally { subscribe.disabled = !supported; }
  });
  action({action:'status'});
})();
