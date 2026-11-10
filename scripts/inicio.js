// IIFE (Immediately Invoked Function Expression) para encapsular el código
(() => {
  // ===============================
  // CONFIGURACIÓN
  // ===============================
  const WHOLESALE_THRESHOLD = 45;
  let CATEGORY_LABELS = {
    'cuidado-personal': 'CUIDADO PERSONAL',
    belleza: 'BELLEZA',
    perfumeria: 'PERFUMERÍA'
  };
  const BUILT_IN_CATEGORY_KEYS = new Set(Object.keys(CATEGORY_LABELS));

  // ===============================
  // ESTADO DEL CARRITO
  // ===============================
  let cartItems = [];
  let products = [];
  let LOW_STOCK_THRESHOLD = 5;
  let INVENTORY_ENABLED = true;
  let deliveryMap = null;
  let deliveryMarker = null;
  let deliveryLocationConfirmed = false;
  let deliveryMapPointChosen = false;
  let deliveryMapZone = '';
  let addressLookupController = null;
  let addressLookupRequestId = 0;
  let lastAddressLookupAt = 0;
  let savedDeliveryZone = 'medellin';
  let savedDeliveryMunicipality = '';
  
  // ===============================
  // LÓGICA DE NOTIFICACIÓN (TOAST)
  // ===============================
  function showCustomToast({ icon, title, subtitle, actionText, actionCallback, cancelText }) {
      const existingToast = document.querySelector('.custom-toast');
      if (existingToast) existingToast.remove();

      const toast = document.createElement('div');
      toast.className = 'custom-toast';
      
      const actionButtonHTML = actionText ? `<button class="toast-action-btn">${actionText}</button>` : '';
      const cancelButtonHTML = cancelText ? `<button class="toast-cancel-btn">${cancelText}</button>` : '';
      const buttonsContainerHTML = (actionText || cancelText) ? `<div class="toast-buttons">${cancelButtonHTML}${actionButtonHTML}</div>` : '';

      toast.innerHTML = `
          <span class="toast-icon">${icon}</span>
          <div class="toast-body">
              <div class="toast-title">${title}</div>
              <span class="toast-subtitle">${subtitle}</span>
          </div>
          ${buttonsContainerHTML}
          <button class="toast-close-btn">×</button>
      `;

      document.body.appendChild(toast);
      setTimeout(() => toast.classList.add('show'), 10);

      const removeToast = () => {
          toast.classList.remove('show');
          toast.addEventListener('transitionend', () => toast.remove());
      };

      toast.querySelector('.toast-close-btn').onclick = removeToast;
      
      if (actionText && actionCallback) {
          toast.querySelector('.toast-action-btn').onclick = () => {
              actionCallback();
              removeToast();
          };
      }
      if (cancelText) {
          toast.querySelector('.toast-cancel-btn').onclick = removeToast;
      }

      if (!actionText && !cancelText) {
        setTimeout(removeToast, 5000);
      }
  }

  let customerCsrfToken = '';
  let customerAccount = { user: null, orders: [] };
  let customerAccountLoadPromise = null;

  function removeLegacyCustomerStorage() {
    try {
      ['aras_customer_accounts', 'aras_customer_session', 'aras_customer_orders']
        .forEach(key => window.localStorage.removeItem(key));
    } catch (error) {
      console.warn('No se pudo eliminar el almacenamiento local anterior de clientes:', error);
    }
  }

  function renderCustomerAccountView() {
    const authView = document.getElementById('account-auth-view');
    const profileView = document.getElementById('account-profile-view');
    const nameEl = document.getElementById('account-user-name');
    const orderList = document.getElementById('customer-order-list');

    if (!authView || !profileView || !nameEl || !orderList) return;

    if (!customerAccount.user) {
      authView.hidden = false;
      profileView.hidden = true;
      return;
    }

    authView.hidden = true;
    profileView.hidden = false;
    nameEl.textContent = customerAccount.user.name || 'Cliente';

    const orders = customerAccount.orders;
    if (!orders.length) {
      orderList.innerHTML = '<div class="customer-order-empty">Aún no tienes pedidos para seguir. Cuando realices tu primera compra, aparecerán aquí.</div>';
      return;
    }

    const orderStatusLabels = {
      pending: 'Pendiente de confirmación',
      sold: 'Pedido confirmado',
      not_concluded: 'Pedido no concluido'
    };
    const paymentStatusLabels = {
      not_applicable: 'No aplica',
      unpaid: 'Pago pendiente',
      paid: 'Pagado',
      expired: 'Pago vencido',
      paid_late: 'Pago recibido, requiere revisión'
    };
    orderList.innerHTML = orders.map(order => `
        <article class="customer-order-item">
          <div class="customer-order-header">
            <span>Pedido</span>
            <strong>${escapeHtml(order.reference || 'Sin referencia')}</strong>
          </div>
          <div class="customer-order-row">
            <span>Estado</span>
            <strong>${escapeHtml(orderStatusLabels[order.status] || 'En proceso')}</strong>
          </div>
          <div class="customer-order-row">
            <span>Pago</span>
            <strong>${escapeHtml(paymentStatusLabels[order.payment_status] || 'Pendiente')}</strong>
          </div>
          <div class="customer-order-row">
            <span>Total</span>
            <strong>${escapeHtml(formatCOP(Number(order.total)))}</strong>
          </div>
          <div class="customer-order-row">
            <span>Fecha</span>
            <strong>${escapeHtml(order.created_at ? new Date(order.created_at.replace(' ', 'T')).toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' }) : 'Reciente')}</strong>
          </div>
        </article>
      `).join('');
  }

  async function loadCustomerAccount(showError = true) {
    if (customerAccountLoadPromise) return customerAccountLoadPromise;
    const loading = (async () => {
      try {
        const response = await fetch('api/cuenta-cliente', {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo cargar Mi cuenta.');
        customerCsrfToken = result.csrf_token || '';
        customerAccount = {
          user: result.authenticated && result.user ? result.user : null,
          orders: Array.isArray(result.orders) ? result.orders : []
        };
      } catch (error) {
        console.error('No se pudo cargar la cuenta del cliente:', error);
        if (showError) {
          const message = document.getElementById('account-auth-message');
          if (message) {
            message.textContent = error instanceof Error ? error.message : 'Inténtalo de nuevo más tarde.';
            message.classList.add('is-error');
          }
        }
      } finally {
        renderCustomerAccountView();
      }
    })();
    customerAccountLoadPromise = loading;
    try {
      await loading;
    } finally {
      if (customerAccountLoadPromise === loading) customerAccountLoadPromise = null;
    }
  }

  async function submitCustomerAccount(action, fields = {}) {
    await loadCustomerAccount(false);
    if (!customerCsrfToken) {
      throw new Error('No se pudo validar la sesión. Recarga la página e inténtalo de nuevo.');
    }

    const response = await fetch('api/cuenta-cliente', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': customerCsrfToken
      },
      credentials: 'same-origin',
      cache: 'no-store',
      body: JSON.stringify({ action, ...fields })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'No se pudo completar la solicitud.');
    customerCsrfToken = result.csrf_token || customerCsrfToken;
    customerAccount = {
      user: result.authenticated && result.user ? result.user : null,
      orders: Array.isArray(result.orders) ? result.orders : []
    };
    renderCustomerAccountView();
    return result;
  }

  function setAccountTab(tabName) {
    const tabs = document.querySelectorAll('.account-tab');
    const loginForm = document.getElementById('account-login-form');
    const registerForm = document.getElementById('account-register-form');

    tabs.forEach(tab => {
      const isActive = tab.dataset.accountTab === tabName;
      tab.classList.toggle('active', isActive);
      tab.setAttribute('aria-selected', String(isActive));
    });

    if (loginForm) loginForm.hidden = tabName !== 'login';
    if (registerForm) registerForm.hidden = tabName !== 'register';
  }

  function openAccountModal() {
    const modal = document.getElementById('account-modal');
    if (!modal) return;
    renderCustomerAccountView();
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    loadCustomerAccount();
  }

  function closeAccountModal() {
    const modal = document.getElementById('account-modal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  function clearAccountMessages() {
    const authMessage = document.getElementById('account-auth-message');
    const registerMessage = document.getElementById('account-register-message');
    if (authMessage) authMessage.textContent = '';
    if (registerMessage) registerMessage.textContent = '';
  }

  async function handleAccountLogin(event) {
    event.preventDefault();
    clearAccountMessages();

    const emailInput = document.getElementById('account-login-email');
    const passwordInput = document.getElementById('account-login-password');
    const authMessage = document.getElementById('account-auth-message');
    if (!emailInput || !passwordInput || !authMessage) return;

    const submitButton = event.submitter;
    if (submitButton) submitButton.disabled = true;
    try {
      const result = await submitCustomerAccount('login', {
        email: emailInput.value.trim(),
        password: passwordInput.value
      });
      event.target.reset();
      showCustomToast({
        icon: '✅',
        title: 'Sesión iniciada',
        subtitle: `Bienvenido, ${result.user.name}.`
      });
    } catch (error) {
      authMessage.textContent = error instanceof Error ? error.message : 'No se pudo iniciar sesión.';
      authMessage.classList.add('is-error');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  }

  async function handleAccountRegister(event) {
    event.preventDefault();
    clearAccountMessages();

    const nameInput = document.getElementById('account-register-name');
    const emailInput = document.getElementById('account-register-email');
    const passwordInput = document.getElementById('account-register-password');
    const registerMessage = document.getElementById('account-register-message');
    if (!nameInput || !emailInput || !passwordInput || !registerMessage) return;

    const submitButton = event.submitter;
    if (submitButton) submitButton.disabled = true;
    try {
      const result = await submitCustomerAccount('register', {
        name: nameInput.value.trim(),
        email: emailInput.value.trim(),
        password: passwordInput.value
      });
      event.target.reset();
      showCustomToast({
        icon: '✅',
        title: 'Cuenta creada',
        subtitle: `Bienvenido, ${result.user.name}.`
      });
    } catch (error) {
      registerMessage.textContent = error instanceof Error ? error.message : 'No se pudo crear la cuenta.';
      registerMessage.classList.add('is-error');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  }

  async function handleAccountLogout() {
    try {
      await submitCustomerAccount('logout');
      showCustomToast({
        icon: 'ℹ️',
        title: 'Sesión cerrada',
        subtitle: 'Puedes volver a entrar cuando quieras.'
      });
    } catch (error) {
      console.error('No se pudo cerrar la sesión del cliente:', error);
      showCustomToast({
        icon: '⚠️',
        title: 'No se pudo cerrar la sesión',
        subtitle: error instanceof Error ? error.message : 'Inténtalo de nuevo.'
      });
    }
  }

  // ===============================
  // LÓGICA DEL MENÚ HAMBURGUESA
  // ===============================
  function toggleNavMenu() {
    const hamburgerBtn = document.getElementById('hamburger-button');
    const navMenu = document.querySelector('.main-nav');
    if (!hamburgerBtn || !navMenu) return;
    const isOpen = hamburgerBtn.classList.toggle('open');
    navMenu.classList.toggle('open', isOpen);
    hamburgerBtn.setAttribute('aria-expanded', String(isOpen));
    hamburgerBtn.setAttribute('aria-label', isOpen ? 'Cerrar menú' : 'Abrir menú');
  }

  // ===============================
  // LÓGICA DEL CARRITO
  // ===============================
  function updateCartCounter() {
    const cartCounterEl = document.getElementById('cart-counter');
    if (!cartCounterEl) return;
    const totalItems = cartItems.reduce((sum, item) => sum + item.qty, 0);
    if (totalItems > 0) {
        cartCounterEl.textContent = totalItems;
        cartCounterEl.classList.add('show');
    } else {
        cartCounterEl.classList.remove('show');
    }
  }
  
  const formatCOP = (amount) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(amount);
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  })[character]);
  function getCartKey(id, variants = []) {
    return JSON.stringify([id, variants]);
  }
  function findItemIndex(cartKey) { return cartItems.findIndex(i => i.cartKey === cartKey); }

  function addItemToCart({ id, name, price, stock, image, variants = [], qty = 1 }) {
    const available = INVENTORY_ENABLED ? stock : 100;
    const cartKey = getCartKey(id, variants);
    const idx = findItemIndex(cartKey);
    const totalInCart = cartItems
      .filter(item => item.id === id)
      .reduce((sum, item) => sum + item.qty, 0);
    if (INVENTORY_ENABLED && totalInCart + qty > available) {
      showCustomToast({ icon: '⚠️', title: 'Inventario limitado', subtitle: `Solo quedan ${Math.max(available - totalInCart, 0)} unidades de ${name}.` });
      return;
    }
    if (idx >= 0) {
      cartItems[idx].qty += qty;
    } else {
      if (INVENTORY_ENABLED && stock < 1) return;
      const variantSummary = variants.map(variant => `${variant.name}: ${variant.value}`).join(' · ');
      cartItems.push({
        id,
        cartKey,
        name: variantSummary ? `${name} · ${variantSummary}` : name,
        price,
        stock: available,
        image,
        variants,
        qty: Math.min(qty, available)
      });
    }
    renderCart();
  }

  function removeItemFromCart(cartKey) {
    cartItems = cartItems.filter(i => i.cartKey !== cartKey);
    renderCart();
  }

  function changeItemQty(cartKey, newQty, input) {
    const idx = findItemIndex(cartKey);
    if (idx < 0) return;

    const item = cartItems[idx];
    const otherItemsQuantity = cartItems
      .filter(cartItem => cartItem.id === item.id && cartItem.cartKey !== cartKey)
      .reduce((sum, cartItem) => sum + cartItem.qty, 0);
    const message = !Number.isInteger(newQty) || newQty < 1
      ? 'Ingresa una cantidad de al menos 1.'
      : newQty > (INVENTORY_ENABLED ? item.stock - otherItemsQuantity : 100)
        ? `Solo hay ${Math.max(item.stock - otherItemsQuantity, 0)} unidades disponibles de ${item.name}.`
        : '';
    const alert = input.closest('.cart-item').querySelector('.item-stock-alert');
    if (message) {
      input.setAttribute('aria-invalid', 'true');
      alert.textContent = message;
      alert.hidden = false;
      return;
    }

    cartItems[idx].qty = newQty;
    renderCart();
  }

  function calculateSubtotal() { return cartItems.reduce((sum, item) => sum + item.price * item.qty, 0); }
  function getDeliveryZone() { return document.getElementById('delivery-zone')?.value || 'medellin'; }
  function getDeliveryMunicipality() {
    if (getDeliveryZone() === 'medellin') return 'Medellín';
    return document.getElementById('delivery-municipality')?.value.trim() || '';
  }
  function updateDeliveryPreferenceUI() {
    const zone = getDeliveryZone();
    const municipalityField = document.getElementById('delivery-municipality-field');
    const municipalityInput = document.getElementById('delivery-municipality');
    const summary = document.getElementById('delivery-preference-summary');
    if (municipalityField) municipalityField.hidden = zone === 'medellin';
    if (municipalityInput) municipalityInput.required = zone !== 'medellin';
    if (summary) {
      summary.textContent = zone === 'medellin'
        ? 'Entrega en Medellín'
        : `Entrega en ${getDeliveryMunicipality() || 'otro municipio de Antioquia'}`;
    }
  }
  function calculateShipping(subtotal) {
    if (subtotal <= 0) return 0;
    const zone = getDeliveryZone();
    if (zone === 'medellin') return 8000;
    if (zone === 'antioquia') return 14000;
    return null;
  }

  function updateTotalsUI() {
    const subtotalEl = document.getElementById('cart-subtotal');
    const shippingEl = document.getElementById('cart-shipping');
    const totalEl = document.getElementById('cart-total');
    const wholesaleNoticeEl = document.getElementById('wholesale-notice');

    const subtotal = calculateSubtotal();
    const shipping = calculateShipping(subtotal);
    const total = shipping === null ? null : subtotal + shipping;

    if (subtotalEl) subtotalEl.textContent = formatCOP(subtotal);
    if (shippingEl) shippingEl.textContent = shipping === null ? 'Por cotizar' : formatCOP(shipping);
    if (totalEl) totalEl.textContent = total === null ? 'Por cotizar' : formatCOP(total);
    updateDeliveryPreferenceUI();
    const checkoutButton = document.getElementById('checkout-button');
    if (checkoutButton) checkoutButton.textContent = 'Continuar con el domicilio';
    
    const totalItems = cartItems.reduce((sum, item) => sum + item.qty, 0);
    if (wholesaleNoticeEl) {
        if (totalItems > WHOLESALE_THRESHOLD) {
            wholesaleNoticeEl.classList.add('show');
        } else {
            wholesaleNoticeEl.classList.remove('show');
        }
    }
  }

  function renderCart() {
    const cartItemsContainer = document.querySelector('.cart-items');
    if (!cartItemsContainer) return;
    cartItemsContainer.innerHTML = cartItems.length === 0 ? '<p class="cart-empty">Tu bolsa está vacía.<br><span>Agrega tus productos favoritos para continuar.</span></p>' :
      cartItems.map(item => `
        <div class="cart-item" data-id="${escapeHtml(item.cartKey)}">
            <img class="cart-item-image" src="${escapeHtml(item.image || '')}" alt="" loading="lazy">
            <div class="item-info">
                <strong class="item-name">${escapeHtml(item.name)}</strong>
                <div class="item-meta">
                    <span class="item-price">${formatCOP(item.price)}</span>
                    <label class="item-quantity-label" for="quantity-${escapeHtml(item.cartKey)}">Cantidad</label>
                    <input id="quantity-${escapeHtml(item.cartKey)}" type="number" min="1" max="${INVENTORY_ENABLED ? item.stock : 100}" value="${item.qty}" class="item-qty" inputmode="numeric" aria-describedby="quantity-error-${escapeHtml(item.cartKey)}">
                </div>
                <p class="item-stock-alert" id="quantity-error-${escapeHtml(item.cartKey)}" role="alert" hidden></p>
                <span class="item-line-total">${formatCOP(item.price * item.qty)}</span>
            </div>
            <button class="item-remove" type="button" aria-label="Quitar ${escapeHtml(item.name)} del carrito" title="Quitar producto">×</button>
        </div>`).join('');
    
    updateTotalsUI();
    updateCartCounter();
    updateProductAvailability();
  }

  function setMapLocationStatus(message, state = '') {
    const status = document.getElementById('map-location-status');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-confirmed', state === 'confirmed');
    status.classList.toggle('is-located', state === 'located');
    status.classList.toggle('is-error', state === 'error');
    status.classList.toggle('is-loading', state === 'loading');
  }

  function clearDeliveryLocationConfirmation() {
    deliveryLocationConfirmed = false;
    deliveryMapPointChosen = false;
    const latitude = document.getElementById('delivery-latitude');
    const longitude = document.getElementById('delivery-longitude');
    if (latitude) latitude.value = '';
    if (longitude) longitude.value = '';
    setMapLocationStatus('Confirma el punto de entrega en el mapa.');
  }

  function setDeliveryMapMarker(latLng) {
    if (!deliveryMap || !deliveryMarker) return;
    deliveryMarker.setLatLng(latLng);
    clearDeliveryLocationConfirmation();
    deliveryMapPointChosen = true;
  }

  function getDeliveryMapView(zone) {
    if (zone === 'medellin') return { center: [6.2442, -75.5812], zoom: 12 };
    if (zone === 'antioquia') return { center: [6.5, -75.5], zoom: 8 };
    return { center: [4.5, -74.2], zoom: 5 };
  }

  function initializeDeliveryMap() {
    const mapContainer = document.getElementById('delivery-map');
    if (!mapContainer || deliveryMap) return;
    if (!window.L) {
      console.error('No se pudo cargar Leaflet para seleccionar la dirección de entrega.');
      setMapLocationStatus('No se pudo cargar el mapa. Recarga la página e inténtalo de nuevo.', 'error');
      return;
    }

    const zone = getDeliveryZone();
    const initialView = getDeliveryMapView(zone);
    deliveryMap = window.L.map(mapContainer, { scrollWheelZoom: false })
      .setView(initialView.center, initialView.zoom);
    deliveryMapZone = zone;
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>'
    }).addTo(deliveryMap);
    deliveryMarker = window.L.marker(initialView.center, { draggable: true }).addTo(deliveryMap);
    deliveryMap.on('click', event => setDeliveryMapMarker(event.latlng));
    deliveryMarker.on('dragend', () => setDeliveryMapMarker(deliveryMarker.getLatLng()));
  }

  async function locateDeliveryAddress() {
    const addressInput = document.getElementById('delivery-address');
    const address = addressInput?.value.trim() || '';
    if (!address) return;
    if (!deliveryMap || !deliveryMarker) {
      setMapLocationStatus('El mapa todavía se está cargando. Inténtalo de nuevo en un momento.', 'error');
      return;
    }

    addressLookupController?.abort();
    const requestId = ++addressLookupRequestId;
    addressLookupController = new AbortController();
    const query = [address, getDeliveryMunicipality(), 'Colombia'].filter(Boolean).join(', ');
    const searchUrl = new URL('https://nominatim.openstreetmap.org/search');
    searchUrl.search = new URLSearchParams({
      q: query,
      format: 'jsonv2',
      addressdetails: '1',
      countrycodes: 'co',
      limit: '1',
      'accept-language': 'es'
    }).toString();
    setMapLocationStatus('Buscando la dirección en el mapa...', 'loading');

    try {
      const waitTime = Math.max(0, 1000 - (Date.now() - lastAddressLookupAt));
      if (waitTime > 0) await new Promise(resolve => window.setTimeout(resolve, waitTime));
      if (requestId !== addressLookupRequestId || addressInput.value.trim() !== address) return;
      lastAddressLookupAt = Date.now();
      const response = await fetch(searchUrl, {
        headers: { Accept: 'application/json' },
        signal: addressLookupController.signal
      });
      if (!response.ok) {
        throw new Error(`El servicio de mapas respondió con estado ${response.status}.`);
      }
      const results = await response.json();
      if (!Array.isArray(results)) {
        throw new Error('El servicio de mapas devolvió una respuesta inválida.');
      }
      if (requestId !== addressLookupRequestId || addressInput.value.trim() !== address) return;
      const result = results[0];
      const latitude = Number(result?.lat);
      const longitude = Number(result?.lon);
      if (!result || result.address?.country_code?.toLowerCase() !== 'co'
          || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        setMapLocationStatus('No encontramos esa dirección en Colombia. Revisa cómo la escribiste o ubica el punto manualmente.', 'error');
        return;
      }

      const point = window.L.latLng(latitude, longitude);
      deliveryMap.setView(point, 17);
      deliveryMarker.setLatLng(point);
      clearDeliveryLocationConfirmation();
      deliveryMapPointChosen = true;
      setMapLocationStatus('Dirección ubicada. Revisa el marcador y confirma el punto de entrega.', 'located');
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      if (requestId !== addressLookupRequestId) return;
      console.error('No se pudo ubicar la dirección con OpenStreetMap Nominatim:', error);
      setMapLocationStatus('No se pudo buscar la dirección. Verifica tu conexión o ubica el punto manualmente.', 'error');
    }
  }

  function updateDeliveryMapView() {
    if (!deliveryMap || !deliveryMarker || deliveryMapZone === getDeliveryZone()) return;
    const view = getDeliveryMapView(getDeliveryZone());
    deliveryMap.setView(view.center, view.zoom);
    deliveryMarker.setLatLng(view.center);
    deliveryMapZone = getDeliveryZone();
    clearDeliveryLocationConfirmation();
  }

  function confirmDeliveryMapLocation() {
    if (!deliveryMap || !deliveryMarker) {
      setMapLocationStatus('El mapa todavía no está disponible. Recarga la página e inténtalo de nuevo.', 'error');
      return;
    }
    if (!deliveryMapPointChosen) {
      setMapLocationStatus('Toca el mapa o arrastra el marcador hasta tu dirección antes de confirmar.', 'error');
      return;
    }
    const point = deliveryMarker.getLatLng();
    document.getElementById('delivery-latitude').value = point.lat.toFixed(7);
    document.getElementById('delivery-longitude').value = point.lng.toFixed(7);
    deliveryLocationConfirmed = true;
    setMapLocationStatus(`Ubicación confirmada (${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}).`, 'confirmed');
  }

  function validateCartQuantities() {
    for (const input of document.querySelectorAll('.cart-item .item-qty')) {
      const id = input.closest('.cart-item').dataset.id;
      const item = cartItems.find(cartItem => cartItem.cartKey === id);
      if (!item) continue;
      const quantity = input.value.trim() === '' ? NaN : Number(input.value);
      const otherItemsQuantity = cartItems
        .filter(cartItem => cartItem.id === item.id && cartItem.cartKey !== item.cartKey)
        .reduce((sum, cartItem) => sum + cartItem.qty, 0);
      const message = !Number.isInteger(quantity) || quantity < 1
        ? 'Ingresa una cantidad de al menos 1.'
        : quantity > (INVENTORY_ENABLED ? item.stock - otherItemsQuantity : 100)
          ? `Solo hay ${Math.max(item.stock - otherItemsQuantity, 0)} unidades disponibles de ${item.name}.`
          : '';
      const alert = input.closest('.cart-item').querySelector('.item-stock-alert');
      if (message) {
        input.setAttribute('aria-invalid', 'true');
        alert.textContent = message;
        alert.hidden = false;
        input.focus();
        return false;
      }
    }
    return true;
  }

  function clearCart() {
      if (cartItems.length === 0) return;
      cartItems = [];
      renderCart();
      showCustomToast({
          icon: '✅',
          title: 'Carrito vaciado',
          subtitle: 'Todos los productos han sido eliminados.'
      });
  }

  function renderProducts() {
    document.querySelectorAll('[data-product-grid]').forEach(grid => {
      const featured = grid.dataset.productGrid === 'featured';
      const visibleProducts = featured ? products.slice(0, 3) : products;
      if (visibleProducts.length === 0) {
        grid.innerHTML = '<p class="catalog-empty">Pronto encontrarás aquí nuestra colección disponible. Estamos preparando nuevos diseños.</p>';
        return;
      }

      grid.replaceChildren(...visibleProducts.map(product => {
        const card = document.createElement('article');
        card.className = 'producto';
        card.dataset.id = product.id;
        card.dataset.category = product.category;
        card.dataset.stock = String(product.stock);

        const imageContainer = document.createElement('div');
        imageContainer.className = 'image-container';
        const image = document.createElement('img');
        image.src = product.image;
        image.alt = `${product.name} de ARAS`;
        image.loading = 'lazy';
        imageContainer.append(image);
        if (product.is_new) {
          const badge = document.createElement('span');
          badge.className = 'product-new-badge';
          badge.textContent = 'Nuevo';
          imageContainer.append(badge);
        }
        const overlay = document.createElement('div');
        overlay.className = 'product-overlay';
        const button = document.createElement('button');
        button.className = 'btn-add-to-cart';
        button.type = 'button';
        button.textContent = INVENTORY_ENABLED && product.stock === 0 ? 'Agotado' : 'Agregar al carrito';
        button.disabled = INVENTORY_ENABLED && product.stock === 0;
        overlay.append(button);
        imageContainer.append(overlay);

        const info = document.createElement('div');
        info.className = 'product-info';
        const category = document.createElement('span');
        category.className = 'product-tag';
        category.textContent = CATEGORY_LABELS[product.category] || '';
        const name = document.createElement('h3');
        name.textContent = product.name;
        const price = document.createElement('p');
        price.className = 'price';
        price.textContent = formatCOP(product.price);
        const stock = document.createElement('p');
        stock.className = INVENTORY_ENABLED && product.stock === 0 ? 'stock-status is-out' : INVENTORY_ENABLED && product.stock <= LOW_STOCK_THRESHOLD ? 'stock-status is-low' : 'stock-status';
        stock.textContent = !INVENTORY_ENABLED ? 'Disponible' : product.stock === 0 ? 'Agotado' : product.stock <= LOW_STOCK_THRESHOLD ? 'Pocas unidades' : 'Disponible';
        const variants = Array.isArray(product.variants) ? product.variants : [];
        if (variants.length > 0) {
          const selectors = document.createElement('div');
          selectors.className = 'product-variants';
          variants.forEach(variant => {
            const group = document.createElement('div');
            group.className = 'product-variant-group';
            group.dataset.variantName = variant.name;
            const label = document.createElement('span');
            label.className = 'product-variant-label';
            label.textContent = variant.name;
            const options = document.createElement('div');
            options.className = 'product-variant-options';
            variant.options.forEach((option, index) => {
              const choice = document.createElement('button');
              choice.className = 'variant-option';
              choice.type = 'button';
              choice.dataset.variantValue = option.label;
              choice.textContent = option.label;
              choice.setAttribute('aria-pressed', String(index === 0));
              if (index === 0) choice.classList.add('is-selected');
              if (Array.isArray(option.colors) && option.colors.length > 0) {
                choice.classList.add('has-color');
                const gradient = option.colors.length > 1
                  ? `linear-gradient(135deg, ${option.colors.join(', ')})`
                  : option.colors[0];
                choice.style.setProperty('--variant-swatch', gradient);
              }
              options.append(choice);
            });
            group.append(label, options);
            selectors.append(group);
          });
          info.append(category, name, selectors, price, stock);
        } else {
          info.append(category, name, price, stock);
        }
        card.append(imageContainer, info);
        return card;
      }));
    });
    updateProductAvailability();
  }

  function renderCategoryCards(categories, categoryImages = {}) {
    const categoryGrid = document.querySelector('.category-grid');
    if (!categoryGrid) return;

    categoryGrid.querySelectorAll('[data-custom-category]').forEach(card => card.remove());
    Object.entries(categories).forEach(([key, label]) => {
      if (BUILT_IN_CATEGORY_KEYS.has(key)) return;

      const button = document.createElement('button');
      button.className = 'category-card';
      button.type = 'button';
      button.dataset.filter = key;
      button.dataset.customCategory = 'true';
      button.setAttribute('aria-label', `Ver categoría ${label}`);

      const image = document.createElement('img');
      image.src = categoryImages[key] || products.find(product => product.category === key)?.image || 'img/aras-banner-demo.svg';
      image.alt = '';
      image.loading = 'lazy';

      const caption = document.createElement('span');
      caption.className = 'category-label';
      caption.textContent = label;
      button.append(image, caption);
      categoryGrid.append(button);
    });
  }

  function updateProductAvailability() {
    document.querySelectorAll('.producto[data-id]').forEach(card => {
      const product = products.find(item => item.id === card.dataset.id);
      if (!product) return;
      const inCart = cartItems
        .filter(item => item.id === product.id)
        .reduce((sum, item) => sum + item.qty, 0);
      const remaining = INVENTORY_ENABLED ? Math.max(0, product.stock - inCart) : 100;
      const status = card.querySelector('.stock-status');
      const button = card.querySelector('.btn-add-to-cart');
      if (status) {
        status.className = INVENTORY_ENABLED && remaining === 0 ? 'stock-status is-out' : INVENTORY_ENABLED && remaining <= LOW_STOCK_THRESHOLD ? 'stock-status is-low' : 'stock-status';
        status.textContent = !INVENTORY_ENABLED ? 'Disponible' : remaining === 0 ? 'Agotado' : remaining <= LOW_STOCK_THRESHOLD ? 'Pocas unidades' : 'Disponible';
      }
      if (button) {
        button.disabled = INVENTORY_ENABLED && remaining === 0;
        button.textContent = INVENTORY_ENABLED && remaining === 0 ? 'Agotado' : 'Agregar al carrito';
      }
    });
  }

  async function loadProducts() {
    try {
      const response = await fetch('api/productos.php', { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.ok) throw new Error(`El servidor respondió con estado ${response.status}.`);
      const data = await response.json();
      if (!Array.isArray(data.products)) throw new Error('La respuesta del catálogo tiene un formato inválido.');
      if (!Number.isInteger(data.low_stock_threshold) || data.low_stock_threshold < 1) {
        throw new Error('El umbral de inventario bajo tiene un formato inválido.');
      }
      if (typeof data.inventory_enabled !== 'boolean') {
        throw new Error('La configuración de inventario tiene un formato inválido.');
      }
      LOW_STOCK_THRESHOLD = data.low_stock_threshold;
      INVENTORY_ENABLED = data.inventory_enabled;
      products = data.products;
      if (data.categories && typeof data.categories === 'object' && !Array.isArray(data.categories)) {
        CATEGORY_LABELS = data.categories;
        renderCategoryCards(data.categories, data.category_images || {});
      }
      renderProducts();
    } catch (error) {
      console.error('No se pudo cargar el catálogo de ARAS:', error);
      document.querySelectorAll('[data-product-grid]').forEach(grid => {
        grid.innerHTML = `
          <section class="catalog-error" role="alert">
            <span class="catalog-error-icon" aria-hidden="true"><i class="fa-regular fa-heart"></i></span>
            <h3>En un momento volvemos</h3>
            <p>Tuvimos un pequeño inconveniente al mostrar nuestros diseños. Vuelve a intentarlo; nos encantará ayudarte a encontrar tu favorito.</p>
            <button class="catalog-error-retry" type="button">Volver a intentar</button>
          </section>
        `;
        grid.querySelector('.catalog-error-retry').addEventListener('click', event => {
          const button = event.currentTarget;
          button.disabled = true;
          button.textContent = 'Cargando diseños...';
          loadProducts();
        });
      });
    }
    setupProductFiltering();
  }

  // ===============================
  // LÓGICA DE BÚSQUEDA Y FILTRADO
  // ===============================
  function setupProductFiltering() {
      const searchBar = document.getElementById('search-bar');
      const categoryGrid = document.querySelector('.category-grid');
      if (!categoryGrid) return;
      const categoryCards = categoryGrid.querySelectorAll('.category-card');

      categoryCards.forEach(button => {
          const filter = button.dataset.filter;
          const categoryAvailable = filter === 'all' || products.some(product => product.category === filter);
          let comingSoon = button.querySelector('.filter-coming-soon');

          if (!categoryAvailable) {
              button.disabled = true;
              button.setAttribute('aria-disabled', 'true');
              button.title = 'Próximamente';
              if (!comingSoon) {
                  comingSoon = document.createElement('span');
                  comingSoon.className = 'filter-coming-soon';
                  comingSoon.textContent = 'Próximamente';
                  button.append(comingSoon);
              }
              return;
          }

          button.disabled = false;
          button.removeAttribute('aria-disabled');
          button.removeAttribute('title');
          comingSoon?.remove();
      });

      const applyFilters = () => {
          const currentFilter = categoryGrid.dataset.currentFilter || 'all';
          const currentSearchTerm = searchBar?.value.toLowerCase().trim() || '';
          categoryGrid.closest('main')?.querySelectorAll('.product-grid .producto').forEach(product => {
              const category = product.dataset.category || '';
              const name = product.querySelector('h3').innerText.toLowerCase();

              const categoryMatch = currentFilter === 'all' || category.toLowerCase() === currentFilter;
              const searchMatch = name.includes(currentSearchTerm);

              if (categoryMatch && searchMatch) {
                  product.classList.remove('product-hidden');
              } else {
                  product.classList.add('product-hidden');
              }
          });
      };

      if (searchBar && searchBar.dataset.filterReady !== 'true') {
          searchBar.dataset.filterReady = 'true';
          searchBar.addEventListener('input', applyFilters);
      }

      if (categoryGrid.dataset.filterReady !== 'true') {
          categoryGrid.dataset.filterReady = 'true';
          categoryGrid.addEventListener('click', event => {
              const button = event.target.closest('.category-card');
              if (!button || button.disabled) return;
              categoryGrid.querySelectorAll('.category-card').forEach(card => {
                  const isSelected = card === button;
                  card.classList.toggle('active', isSelected);
                  card.setAttribute('aria-pressed', String(isSelected));
              });
              categoryGrid.dataset.currentFilter = button.dataset.filter || 'all';
              applyFilters();
              document.getElementById('coleccion')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
      }
      applyFilters();
  }

  // ===============================
  // NUEVO: LÓGICA PARA EL ACORDEÓN DE FAQ
  // ===============================
  function setupFAQAccordion() {
      const faqItems = document.querySelectorAll('.faq-item');
      if (faqItems.length === 0) return;

      faqItems.forEach(item => {
          const question = item.querySelector('.faq-question');
          question.addEventListener('click', () => {
              faqItems.forEach(otherItem => {
                  if (otherItem !== item) {
                      otherItem.classList.remove('active');
                  }
              });
              item.classList.toggle('active');
          });
      });
  }

  // ===============================
  // GESTIÓN DE EVENTOS
  // ===============================
  function addCartItemListeners() {
    const cartItemsContainer = document.querySelector('.cart-items');
    if (!cartItemsContainer) return;
    
    cartItemsContainer.addEventListener('change', (event) => {
        if (event.target.classList.contains('item-qty')) {
            const id = event.target.closest('.cart-item').dataset.id;
            changeItemQty(
              id,
              event.target.value.trim() === '' ? NaN : Number(event.target.value),
              event.target
            );
        }
    });

    cartItemsContainer.addEventListener('click', (event) => {
        if (event.target.classList.contains('item-remove')) {
            const id = event.target.closest('.cart-item').dataset.id;
            removeItemFromCart(id);
        }
    });
  }

  function toggleCart() { 
    const cartPanel = document.getElementById('cart');
    cartPanel.classList.toggle('open'); 
  }
  function openModal() { 
    const modal = document.getElementById('checkout-modal');
    if (modal) {
      const municipality = getDeliveryMunicipality();
      const zone = getDeliveryZone();
      const zoneLabel = zone === 'medellin'
        ? 'Domicilio $8.000 · Medellín'
        : 'Domicilio $14.000 · Antioquia';
      const summary = document.getElementById('delivery-location-summary');
      if (summary) summary.textContent = `${municipality || 'Municipio pendiente'} · ${zoneLabel}`;
      modal.style.display = 'flex';
      window.setTimeout(() => {
        initializeDeliveryMap();
        updateDeliveryMapView();
        deliveryMap?.invalidateSize();
      }, 100);
    }
  }
  function closeModal() { 
    const modal = document.getElementById('checkout-modal');
    if (modal) modal.style.display = 'none'; 
  }
  function openDeliveryPreferencesModal() {
    const modal = document.getElementById('delivery-preferences-modal');
    if (!modal) return;
    savedDeliveryZone = getDeliveryZone();
    savedDeliveryMunicipality = document.getElementById('delivery-municipality')?.value.trim() || '';
    updateDeliveryPreferenceUI();
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('delivery-zone')?.focus();
  }
  function closeDeliveryPreferencesModal(restore = true) {
    const modal = document.getElementById('delivery-preferences-modal');
    if (!modal) return;
    if (restore) {
      const zone = document.getElementById('delivery-zone');
      const municipality = document.getElementById('delivery-municipality');
      if (zone) zone.value = savedDeliveryZone;
      if (municipality) municipality.value = savedDeliveryMunicipality;
      updateDeliveryPreferenceUI();
    }
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }
  function saveDeliveryPreferences(event) {
    event.preventDefault();
    if (!getDeliveryMunicipality()) {
      document.getElementById('delivery-municipality')?.focus();
      return;
    }
    updateTotalsUI();
    clearDeliveryLocationConfirmation();
    updateDeliveryMapView();
    closeDeliveryPreferencesModal(false);
  }

  function handleCheckout() {
    if (cartItems.length === 0) {
        showCustomToast({
            icon: '💗',
            title: 'Tu bolsa está vacía',
            subtitle: 'Añade tus artículos favoritos y vuelve cuando estés lista.',
            actionText: 'Ver productos',
            actionCallback: () => {
                window.location.href = './#coleccion';
            }
        });
        return;
    }
    if (!validateCartQuantities()) return;

    if (!getDeliveryMunicipality()) {
      openDeliveryPreferencesModal();
      showCustomToast({
        icon: '📍',
        title: 'Indica el municipio',
        subtitle: 'Escribe el municipio donde quieres recibir tu pedido.'
      });
      return;
    }
    openModal();
  }
  
  function handleDeliveryFormSubmit(e) {
    e.preventDefault();
    const address = document.getElementById('delivery-address').value.trim();
    const name = document.getElementById('contact-name').value.trim();
    const phone = document.getElementById('contact-phone').value.trim();
    const municipality = getDeliveryMunicipality();
    const zone = getDeliveryZone();
    const latitude = Number(document.getElementById('delivery-latitude').value);
    const longitude = Number(document.getElementById('delivery-longitude').value);
    if (!address || !name || !phone || !municipality || !['medellin', 'antioquia'].includes(zone)) {
        alert('Por favor completa todos los campos de envío.');
        return;
    }
    if (!deliveryLocationConfirmed || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
      setMapLocationStatus('Confirma primero el punto de entrega en el mapa.', 'error');
      document.getElementById('confirm-map-location')?.focus();
      return;
    }
    if (!validateCartQuantities()) return;
    submitCustomerOrder({ address, name, phone, city: municipality, zone, latitude, longitude });
  }

  async function submitCustomerOrder(details) {
      const submitButton = document.querySelector('#delivery-form [type="submit"]');
      let createdOrderReference = '';
      if (submitButton) submitButton.disabled = true;

      try {
          if (typeof window.WidgetCheckout !== 'function') {
              throw new Error('No se pudo cargar el pago seguro de Wompi. Revisa tu conexión e inténtalo de nuevo.');
          }
          if (!customerCsrfToken) {
            await loadCustomerAccount(false);
          }
          if (!customerCsrfToken) {
            throw new Error('No se pudo validar la sesión. Recarga la página e inténtalo de nuevo.');
          }
          const response = await fetch('api/pedido', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': customerCsrfToken
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                  items: cartItems.map(item => ({
                    id: item.id,
                    quantity: item.qty,
                    variants: item.variants || []
                  })),
                  method: 'delivery',
                  city: details.city,
                  delivery_zone: details.zone,
                  name: details.name || '',
                  phone: details.phone || '',
                  address: details.address || '',
                  latitude: details.latitude,
                  longitude: details.longitude
              }),
              cache: 'no-store'
          });
          const result = await response.json();
          if (!response.ok || !result.order || !result.checkout) {
              throw new Error(result.error || 'No se pudo registrar el pedido y preparar su pago.');
          }

          const order = result.order;
          createdOrderReference = order.reference;
          closeModal();
          await loadCustomerAccount(false);

          const checkout = new window.WidgetCheckout({
              currency: result.checkout.currency,
              amountInCents: result.checkout.amount_in_cents,
              reference: result.checkout.reference,
              publicKey: result.checkout.public_key,
              signature: { integrity: result.checkout.integrity_signature }
          });
          checkout.open((paymentResult) => {
              const paymentStatus = paymentResult?.transaction?.status;
              if (paymentStatus === 'APPROVED') {
                  cartItems = [];
                  renderCart();
                  document.getElementById('cart')?.classList.remove('open');
                  loadCustomerAccount(false);
                  showCustomToast({
                      icon: '✅',
                      title: 'Pago recibido',
                      subtitle: `El pago del pedido ${order.reference} fue aprobado. Estamos confirmando el pedido.`
                  });
                  return;
              }
              showCustomToast({
                  icon: 'ℹ️',
                  title: 'Pedido pendiente de pago',
                  subtitle: `Pedido ${order.reference}. Puedes completar el pago durante las próximas 24 horas.`
              });
          });
      } catch (error) {
          console.error('No se pudo registrar el pedido de ARAS:', error);
          showCustomToast({
              icon: '⚠️',
              title: 'No se pudo iniciar el pago',
              subtitle: `${error instanceof Error ? error.message : 'Inténtalo de nuevo en unos minutos.'}${createdOrderReference ? ` El pedido ${createdOrderReference} quedó pendiente de pago por 24 horas.` : ''}`
          });
      } finally {
          if (submitButton) submitButton.disabled = false;
      }
  }

  // ===============================
  // INICIALIZACIÓN Y EVENTOS PRINCIPALES
  // ===============================
  document.addEventListener('DOMContentLoaded', () => {
    const logoImage = document.querySelector('.logo-image');
    const logoFallback = document.querySelector('.logo-fallback');
    if (logoImage && logoFallback) {
      const showLogo = () => {
        logoImage.hidden = false;
        logoFallback.hidden = true;
      };
      const showLogoFallback = () => {
        logoImage.hidden = true;
        logoFallback.hidden = false;
      };

      logoImage.addEventListener('load', showLogo, { once: true });
      logoImage.addEventListener('error', showLogoFallback, { once: true });
      if (logoImage.complete) {
        logoImage.naturalWidth > 0 ? showLogo() : showLogoFallback();
      }
    }

    const cartIcon = document.querySelector('.cart-icon');
    const cartCloseBtn = document.getElementById('cart-close');
    const clearBtn = document.getElementById('clear-cart');
    const checkoutBtn = document.getElementById('checkout-button');
    const hamburgerBtn = document.getElementById('hamburger-button');
    const navMenu = document.querySelector('.main-nav');
    const modalCloseBtn = document.getElementById('modal-close');
    const modalCancelBtn = document.getElementById('modal-cancel');
    const deliveryForm = document.getElementById('delivery-form');
    const deliveryAddressInput = document.getElementById('delivery-address');
    const deliveryZoneSelect = document.getElementById('delivery-zone');
    const deliveryMunicipalityInput = document.getElementById('delivery-municipality');
    const deliveryPreferencesForm = document.getElementById('delivery-preferences-form');
    const deliveryPreferencesModal = document.getElementById('delivery-preferences-modal');
    const deliveryPreferencesButton = document.getElementById('edit-delivery-preference');
    const deliveryPreferencesClose = document.getElementById('delivery-preferences-close');
    const deliveryPreferencesCancel = document.getElementById('delivery-preferences-cancel');
    const confirmMapButton = document.getElementById('confirm-map-location');
    
    loadCustomerAccount(false);
    removeLegacyCustomerStorage();
    renderCart();
    addCartItemListeners();
    setupFAQAccordion(); // LLAMADA A LA NUEVA FUNCIÓN
    loadProducts();

    if (hamburgerBtn) hamburgerBtn.addEventListener('click', toggleNavMenu);
    if (navMenu) {
      navMenu.addEventListener('click', event => {
        if (event.target.closest('a') && navMenu.classList.contains('open')) toggleNavMenu();
      });
    }
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && navMenu?.classList.contains('open')) toggleNavMenu();
    });
    if (cartIcon) cartIcon.addEventListener('click', toggleCart);
    if (cartCloseBtn) cartCloseBtn.addEventListener('click', toggleCart);
    if (clearBtn) clearBtn.addEventListener('click', clearCart);
    if (checkoutBtn) checkoutBtn.addEventListener('click', handleCheckout);
    
    document.body.addEventListener('click', (event) => {
        const variantButton = event.target.closest('.variant-option');
        if (variantButton) {
            const group = variantButton.closest('.product-variant-group');
            group?.querySelectorAll('.variant-option').forEach(option => {
                const isSelected = option === variantButton;
                option.classList.toggle('is-selected', isSelected);
                option.setAttribute('aria-pressed', String(isSelected));
            });
            return;
        }

        const addButton = event.target.closest('.btn-add-to-cart');
        if (addButton && !addButton.disabled) {
            const productCard = addButton.closest('.producto');
            const product = products.find(item => item.id === productCard?.dataset.id);
            if (product) {
                const variants = Array.from(productCard.querySelectorAll('.product-variant-group')).map(group => ({
                    name: group.dataset.variantName,
                    value: group.querySelector('.variant-option.is-selected')?.dataset.variantValue || ''
                }));
                addItemToCart({ ...product, variants });
            }
        }
    });

    if (deliveryPreferencesButton) deliveryPreferencesButton.addEventListener('click', openDeliveryPreferencesModal);
    if (deliveryPreferencesClose) deliveryPreferencesClose.addEventListener('click', closeDeliveryPreferencesModal);
    if (deliveryPreferencesCancel) deliveryPreferencesCancel.addEventListener('click', closeDeliveryPreferencesModal);
    if (deliveryPreferencesForm) deliveryPreferencesForm.addEventListener('submit', saveDeliveryPreferences);
    if (deliveryZoneSelect) {
      deliveryZoneSelect.addEventListener('change', () => {
        if (deliveryZoneSelect.value === 'medellin' && deliveryMunicipalityInput) {
          deliveryMunicipalityInput.value = '';
        }
        updateDeliveryPreferenceUI();
      });
    }
    if (deliveryMunicipalityInput) {
      deliveryMunicipalityInput.addEventListener('input', () => {
        updateDeliveryPreferenceUI();
      });
    }
    if (deliveryAddressInput) {
      deliveryAddressInput.addEventListener('input', () => {
        addressLookupController?.abort();
        addressLookupRequestId += 1;
        clearDeliveryLocationConfirmation();
        if (deliveryAddressInput.value.trim()) {
          setMapLocationStatus('Al terminar de escribir, sal del campo para ubicar la dirección automáticamente.');
        }
      });
      deliveryAddressInput.addEventListener('blur', locateDeliveryAddress);
    }
    if (confirmMapButton) confirmMapButton.addEventListener('click', confirmDeliveryMapLocation);

    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
    if (modalCancelBtn) modalCancelBtn.addEventListener('click', closeModal);
    if (deliveryForm) deliveryForm.addEventListener('submit', handleDeliveryFormSubmit);
    window.addEventListener('click', (e) => { 
        const modal = document.getElementById('checkout-modal');
        if (e.target === modal) closeModal(); 
        if (e.target === deliveryPreferencesModal) closeDeliveryPreferencesModal();
    });

    // --- LÓGICA PARA BLOQUEAR CLIC DERECHO Y ATAJOS ---
    
    document.addEventListener('contextmenu', (event) => {
        event.preventDefault();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'F12' || (event.ctrlKey && event.shiftKey && ['I', 'J', 'C'].includes(event.key.toUpperCase())) || (event.metaKey && event.altKey && ['I', 'J', 'C'].includes(event.key.toUpperCase())) || (event.ctrlKey && event.key.toUpperCase() === 'U')) {
            event.preventDefault();
        }
    });

  });

})();
