(() => {
  const endpoint = 'api/cuenta-cliente';
  const authView = document.getElementById('customer-auth-view');
  const profileView = document.getElementById('customer-profile-view');
  const notice = document.querySelector('[data-account-notice]');
  const loginForm = document.getElementById('customer-login-form');
  const registerForm = document.getElementById('customer-register-form');
  const orderList = document.querySelector('[data-customer-orders]');
  let csrfToken = '';
  let activeTab = 'login';

  const statusLabels = {
    pending: 'Pendiente de confirmación',
    sold: 'Pedido confirmado',
    not_concluded: 'Pedido no concluido'
  };
  const paymentLabels = {
    not_applicable: 'No aplica',
    unpaid: 'Pago pendiente',
    paid: 'Pagado',
    expired: 'Pago vencido',
    paid_late: 'Pago recibido, requiere revisión'
  };

  function showNotice(message, isError = true) {
    if (!notice) return;
    notice.hidden = !message;
    notice.className = isError
      ? 'form-error customer-account-notice'
      : 'form-success customer-account-notice';
    notice.textContent = message;
  }

  function setFormMessage(form, message, isError = true) {
    const target = form?.querySelector('[data-form-message]');
    if (!target) return;
    target.textContent = message;
    target.classList.toggle('is-error', isError && message !== '');
  }

  async function readResponse(response) {
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error('No se pudo leer la respuesta del servidor. Inténtalo de nuevo.');
    }
    if (!response.ok) throw new Error(result.error || 'No se pudo completar la solicitud.');
    return result;
  }

  async function request(action, fields = {}) {
    if (!csrfToken) {
      await loadAccount();
    }
    if (!csrfToken) throw new Error('No se pudo validar la sesión. Recarga la página e inténtalo de nuevo.');

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      cache: 'no-store',
      body: JSON.stringify({ action, ...fields })
    });
    const result = await readResponse(response);
    csrfToken = result.csrf_token || '';
    return result;
  }

  function createOrderRow(label, value) {
    const row = document.createElement('div');
    row.className = 'customer-order-row';
    const name = document.createElement('span');
    name.textContent = label;
    const content = document.createElement('strong');
    content.textContent = value;
    row.append(name, content);
    return row;
  }

  function formatDate(value) {
    if (typeof value !== 'string' || value === '') return 'Reciente';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime())
      ? 'Reciente'
      : new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'long', year: 'numeric' }).format(date);
  }

  function renderOrders(orders) {
    if (!orderList) return;
    orderList.replaceChildren();
    if (!Array.isArray(orders) || orders.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'customer-order-empty';
      empty.textContent = 'Aún no tienes pedidos para seguir. Cuando realices tu primera compra, aparecerán aquí.';
      orderList.append(empty);
      return;
    }

    const money = new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0
    });
    orders.forEach(order => {
      const card = document.createElement('article');
      card.className = 'customer-order-item';
      const heading = document.createElement('div');
      heading.className = 'customer-order-header';
      const title = document.createElement('span');
      title.textContent = 'Pedido';
      const reference = document.createElement('strong');
      reference.textContent = typeof order.reference === 'string' ? order.reference : 'Sin referencia';
      heading.append(title, reference);
      card.append(
        heading,
        createOrderRow('Estado', statusLabels[order.status] || 'En proceso'),
        createOrderRow('Pago', paymentLabels[order.payment_status] || 'Pendiente'),
        createOrderRow('Total', money.format(Number(order.total) || 0)),
        createOrderRow('Fecha', formatDate(order.created_at))
      );
      orderList.append(card);
    });
  }

  function renderAccount(result) {
    const isAuthenticated = result.authenticated === true && result.user;
    if (authView) authView.hidden = Boolean(isAuthenticated);
    if (profileView) profileView.hidden = !isAuthenticated;
    if (!isAuthenticated) return;
    const customerName = document.querySelector('[data-customer-name]');
    if (customerName) customerName.textContent = result.user.name || 'Cliente';
    renderOrders(result.orders);
  }

  async function loadAccount() {
    try {
      const response = await fetch(endpoint, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const result = await readResponse(response);
      csrfToken = result.csrf_token || '';
      renderAccount(result);
      showNotice('');
    } catch (error) {
      showNotice(error instanceof Error ? error.message : 'No se pudo cargar Mi cuenta.');
    }
  }

  function setTab(tabName) {
    activeTab = tabName === 'register' ? 'register' : 'login';
    setFormMessage(loginForm, '');
    setFormMessage(registerForm, '');
    document.querySelectorAll('[data-account-tab]').forEach(tab => {
      const selected = tab.dataset.accountTab === activeTab;
      tab.classList.toggle('active', selected);
      tab.setAttribute('aria-selected', String(selected));
      tab.setAttribute('tabindex', selected ? '0' : '-1');
    });
    if (loginForm) loginForm.hidden = activeTab !== 'login';
    if (registerForm) registerForm.hidden = activeTab !== 'register';
    const title = document.getElementById('customer-auth-title');
    const description = document.querySelector('[data-auth-description]');
    if (title) title.textContent = activeTab === 'login' ? 'Qué alegría tenerte de vuelta' : 'Crea tu cuenta';
    if (description) {
      description.textContent = activeTab === 'login'
        ? 'Inicia sesión para consultar el seguimiento de tus pedidos.'
        : 'Regístrate para consultar tus pedidos en un solo lugar.';
    }
    showNotice('');
  }

  async function handleAccountSubmit(event, action) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    const label = submitButton?.querySelector('[data-submit-label]');
    const originalLabel = label?.textContent || '';
    setFormMessage(form, '');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
      submitButton.setAttribute('aria-busy', 'true');
    }
    if (label) label.textContent = action === 'login' ? 'Iniciando sesión…' : 'Creando cuenta…';

    try {
      const fields = Object.fromEntries(new FormData(form).entries());
      const result = await request(action, fields);
      form.reset();
      renderAccount(result);
      showNotice(action === 'login' ? 'Sesión iniciada.' : 'Tu cuenta fue creada.', false);
    } catch (error) {
      setFormMessage(form, error instanceof Error ? error.message : 'No se pudo completar la solicitud.');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.classList.remove('is-loading');
        submitButton.removeAttribute('aria-busy');
      }
      if (label) label.textContent = originalLabel;
    }
  }

  document.querySelectorAll('[data-account-tab]').forEach(tab => {
    tab.addEventListener('click', () => setTab(tab.dataset.accountTab));
  });
  loginForm?.addEventListener('submit', event => handleAccountSubmit(event, 'login'));
  registerForm?.addEventListener('submit', event => handleAccountSubmit(event, 'register'));

  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const input = button.parentElement?.querySelector('input');
      if (!input) return;
      const visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.textContent = visible ? 'Ocultar' : 'Mostrar';
      button.setAttribute('aria-pressed', String(visible));
    });
  });

  document.querySelector('[data-customer-logout]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const result = await request('logout');
      renderAccount(result);
      setTab('login');
      showNotice('Sesión cerrada.', false);
    } catch (error) {
      showNotice(error instanceof Error ? error.message : 'No se pudo cerrar la sesión.');
    } finally {
      button.disabled = false;
    }
  });

  setTab('login');
  loadAccount();
})();
