(() => {
  const MAX_FILE_SIZE = 5 * 1024 * 1024;
  const ALLOWED_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);

  document.querySelectorAll('form').forEach(form => {
    if (form.querySelector('input[name="action"][value="login"]') === null) return;

    form.addEventListener('submit', () => {
      const submitButton = form.querySelector('[data-login-submit]');
      const label = submitButton?.querySelector('[data-login-submit-label]');
      if (!submitButton || !label) return;

      submitButton.disabled = true;
      submitButton.classList.add('is-loading');
      submitButton.setAttribute('aria-busy', 'true');
      label.textContent = 'Iniciando sesión…';
    });
  });

  document.querySelectorAll('[data-variant-builder]').forEach(builder => {
    const valueInput = builder.querySelector('input[name="variants_json"]');
    const groupContainer = builder.querySelector('[data-variant-groups]');
    const addGroupButton = builder.querySelector('[data-add-variant-group]');
    if (!valueInput || !groupContainer || !addGroupButton) return;

    const parseInitialGroups = () => {
      try {
        const groups = JSON.parse(valueInput.value || '[]');
        if (!Array.isArray(groups)) throw new Error('El formato guardado no es una lista.');
        return groups;
      } catch (error) {
        const message = document.createElement('p');
        message.className = 'variant-builder-error';
        message.setAttribute('role', 'alert');
        message.textContent = 'No se pudieron cargar las opciones guardadas. Revisa este producto antes de guardarlo.';
        builder.prepend(message);
        console.error('No se pudieron leer las opciones del producto:', error);
        return [];
      }
    };

    const sync = () => {
      const groups = Array.from(groupContainer.querySelectorAll('[data-variant-group]')).map(group => ({
        name: group.querySelector('[data-variant-name]')?.value.trim() || '',
        options: Array.from(group.querySelectorAll('[data-variant-option]')).map(option => {
          const item = { label: option.querySelector('[data-option-label]')?.value.trim() || '' };
          if (option.querySelector('[data-option-has-colors]')?.checked) {
            item.colors = Array.from(option.querySelectorAll('[data-option-color]'))
              .map(color => color.value)
              .slice(0, 4);
          }
          return item;
        })
      }));
      valueInput.value = JSON.stringify(groups);
      addGroupButton.disabled = groups.length >= 3;
    };

    const createElement = (tagName, className, text) => {
      const element = document.createElement(tagName);
      if (className) element.className = className;
      if (text !== undefined) element.textContent = text;
      return element;
    };

    const addColorPicker = (colorList, color = '#000000') => {
      const row = createElement('div', 'variant-color-item');
      const input = document.createElement('input');
      input.type = 'color';
      input.value = /^#[a-fA-F0-9]{6}$/.test(color) ? color : '#000000';
      input.setAttribute('aria-label', 'Seleccionar color');
      input.dataset.optionColor = '';
      const remove = createElement('button', 'variant-remove-color', 'Quitar');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Quitar tono del degradado');
      remove.addEventListener('click', () => {
        const option = row.closest('[data-variant-option]');
        row.remove();
        sync();
        if (option) updateColorControls(option);
      });
      input.addEventListener('input', sync);
      row.append(input, remove);
      colorList.append(row);
    };

    const updateColorControls = option => {
      const colorsEnabled = option.querySelector('[data-option-has-colors]');
      const colorTools = option.querySelector('[data-option-color-tools]');
      const colorList = option.querySelector('[data-option-color-list]');
      const addColorButton = option.querySelector('[data-add-option-color]');
      if (!colorsEnabled || !colorTools || !colorList || !addColorButton) return;
      colorTools.hidden = !colorsEnabled.checked;
      const colorCount = colorList.querySelectorAll('[data-option-color]').length;
      addColorButton.disabled = colorCount >= 4;
      colorList.querySelectorAll('.variant-remove-color').forEach(button => {
        button.hidden = colorCount < 2;
      });
    };

    const addOption = (optionsContainer, initial = {}) => {
      const option = createElement('div', 'variant-option-builder');
      option.dataset.variantOption = '';
      const label = document.createElement('input');
      label.type = 'text';
      label.maxLength = 50;
      label.placeholder = 'Nombre de la opción (p. ej. 50 ml)';
      label.required = true;
      label.value = typeof initial.label === 'string' ? initial.label : '';
      label.dataset.optionLabel = '';
      const remove = createElement('button', 'variant-remove-option', 'Quitar opción');
      remove.type = 'button';
      remove.addEventListener('click', () => {
        option.remove();
        sync();
        updateOptionControls(optionsContainer);
      });

      const colorToggleLabel = createElement('label', 'variant-color-toggle');
      const colorToggle = document.createElement('input');
      colorToggle.type = 'checkbox';
      colorToggle.dataset.optionHasColors = '';
      const initialColors = Array.isArray(initial.colors) ? initial.colors.slice(0, 4) : [];
      colorToggle.checked = initialColors.length > 0;
      colorToggleLabel.append(colorToggle, document.createTextNode(' Agregar muestra de color'));
      const colorTools = createElement('div', 'variant-color-tools');
      colorTools.dataset.optionColorTools = '';
      colorTools.hidden = !colorToggle.checked;
      const colorList = createElement('div', 'variant-color-list');
      colorList.dataset.optionColorList = '';
      const addColorButton = createElement('button', 'button-secondary variant-add-color', '+ Añadir tono al degradado');
      addColorButton.type = 'button';
      addColorButton.dataset.addOptionColor = '';
      initialColors.forEach(color => addColorPicker(colorList, color));
      if (colorToggle.checked && initialColors.length === 0) addColorPicker(colorList);
      addColorButton.addEventListener('click', () => {
        if (colorList.querySelectorAll('[data-option-color]').length >= 4) return;
        addColorPicker(colorList);
        sync();
        updateColorControls(option);
      });
      colorToggle.addEventListener('change', () => {
        if (colorToggle.checked && colorList.children.length === 0) addColorPicker(colorList);
        updateColorControls(option);
        sync();
      });
      colorTools.append(colorList, addColorButton);
      option.append(label, remove, colorToggleLabel, colorTools);
      label.addEventListener('input', sync);
      updateColorControls(option);
      optionsContainer.append(option);
    };

    const updateOptionControls = optionsContainer => {
      const options = Array.from(optionsContainer.querySelectorAll('[data-variant-option]'));
      const addButton = optionsContainer.querySelector('[data-add-variant-option]');
      if (addButton) addButton.disabled = options.length >= 30;
      options.forEach(option => {
        const removeButton = option.querySelector('.variant-remove-option');
        if (removeButton) removeButton.hidden = options.length <= 1;
      });
    };

    const addGroup = (initial = {}) => {
      const group = createElement('fieldset', 'variant-group-builder');
      group.dataset.variantGroup = '';
      const heading = createElement('div', 'variant-group-heading');
      const nameLabel = createElement('label', '', 'Nombre de la opción que verá el cliente');
      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.maxLength = 40;
      nameInput.placeholder = 'Ej. Tamaño, Color o Talla';
      nameInput.required = true;
      nameInput.value = typeof initial.name === 'string' ? initial.name : '';
      nameInput.dataset.variantName = '';
      nameLabel.append(nameInput);
      const removeGroup = createElement('button', 'variant-remove-group', 'Quitar grupo');
      removeGroup.type = 'button';
      removeGroup.addEventListener('click', () => {
        group.remove();
        sync();
      });
      heading.append(nameLabel, removeGroup);
      const optionsContainer = createElement('div', 'variant-options-builder');
      const initialOptions = Array.isArray(initial.options) && initial.options.length > 0
        ? initial.options.slice(0, 30)
        : [{}];
      initialOptions.forEach(option => addOption(optionsContainer, option));
      const addOptionButton = createElement('button', 'button-secondary variant-add-option', '+ Añadir otra opción');
      addOptionButton.type = 'button';
      addOptionButton.dataset.addVariantOption = '';
      addOptionButton.addEventListener('click', () => {
        if (optionsContainer.querySelectorAll('[data-variant-option]').length >= 30) return;
        addOption(optionsContainer);
        updateOptionControls(optionsContainer);
        sync();
      });
      optionsContainer.append(addOptionButton);
      nameInput.addEventListener('input', sync);
      group.append(heading, optionsContainer);
      groupContainer.append(group);
      updateOptionControls(optionsContainer);
      sync();
    };

    addGroupButton.addEventListener('click', () => {
      if (groupContainer.querySelectorAll('[data-variant-group]').length >= 3) return;
      addGroup();
    });
    groupContainer.addEventListener('input', sync);
    groupContainer.addEventListener('change', sync);

    parseInitialGroups().slice(0, 3).forEach(addGroup);
    sync();
  });

  document.querySelectorAll('[data-logout-form]').forEach(form => {
    let logoutPending = false;

    form.addEventListener('submit', event => {
      if (logoutPending) return;
      event.preventDefault();
      logoutPending = true;

      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
      }

      const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reducedMotion) {
        form.submit();
        return;
      }

      const overlay = document.createElement('div');
      overlay.className = 'logout-transition';
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'polite');
      overlay.innerHTML = `
        <div class="logout-transition-card">
          <span class="logout-transition-spinner" aria-hidden="true"></span>
          <strong>Cerrando sesión</strong>
          <span>Gracias por cuidar tu tienda</span>
        </div>`;
      document.body.append(overlay);

      window.setTimeout(() => form.submit(), 650);
    });
  });

  document.querySelectorAll('[data-image-gallery-upload]').forEach(gallery => {
    const input = gallery.querySelector('[data-image-gallery-input]');
    const galleryItems = gallery.querySelector('[data-gallery-items]');
    const status = gallery.querySelector('[data-gallery-status]');
    const existingCount = Number.parseInt(gallery.dataset.existingCount || '0', 10);
    const required = gallery.dataset.required === 'true';
    const maxPhotos = 10;
    const maxFileSize = 3 * 1024 * 1024;
    const previewUrls = [];

    if (!input || !galleryItems || !status) return;

    const clearPreviews = () => {
      previewUrls.forEach(url => URL.revokeObjectURL(url));
      previewUrls.length = 0;
      galleryItems.querySelectorAll('.product-gallery-item[data-image-type="new"]').forEach(item => item.remove());
    };

    const updateGalleryOrder = () => {
      const items = Array.from(galleryItems.querySelectorAll('[data-gallery-item]'));
      items.forEach((item, index) => {
        const key = item.dataset.imageType === 'new'
          ? `new:${item.dataset.imageIndex}`
          : item.dataset.imageKey;
        const orderInput = item.querySelector('input[name="image_order[]"]');
        const position = item.querySelector('.product-gallery-position');
        const moveUp = item.querySelector('[data-gallery-move="up"]');
        const moveDown = item.querySelector('[data-gallery-move="down"]');
        if (orderInput) orderInput.value = key || '';
        if (position) position.textContent = index === 0 ? 'Principal' : `Foto ${index + 1}`;
        if (moveUp) {
          moveUp.disabled = index === 0;
          moveUp.setAttribute('aria-label', `Mover foto ${index + 1} arriba`);
        }
        if (moveDown) {
          moveDown.disabled = index === items.length - 1;
          moveDown.setAttribute('aria-label', `Mover foto ${index + 1} abajo`);
        }
      });
    };

    input.addEventListener('change', () => {
      clearPreviews();
      status.classList.remove('gallery-upload-error');

      const files = Array.from(input.files || []);
      if (files.length === 0) {
        updateGalleryOrder();
        status.textContent = required
          ? 'La primera foto será la principal. Selecciona al menos una foto.'
          : `Hay ${existingCount} de ${maxPhotos} fotos. Puedes añadir ${maxPhotos - existingCount} más y cambiar su orden con las flechas.`;
        return;
      }
      if (existingCount + files.length > maxPhotos) {
        input.value = '';
        status.textContent = `Este producto ya tiene ${existingCount} fotos. Solo puedes añadir ${maxPhotos - existingCount} más.`;
        status.classList.add('gallery-upload-error');
        return;
      }

      const invalidFile = files.find(file => !ALLOWED_TYPES.has(file.type) || file.size > maxFileSize);
      if (invalidFile) {
        input.value = '';
        status.textContent = invalidFile.size > maxFileSize
          ? `“${invalidFile.name}” supera el límite de 3 MB.`
          : `“${invalidFile.name}” no es JPG, PNG o WebP.`;
        status.classList.add('gallery-upload-error');
        return;
      }

      files.forEach((file, index) => {
        const previewUrl = URL.createObjectURL(file);
        previewUrls.push(previewUrl);
        const item = document.createElement('div');
        item.className = 'product-gallery-item is-new-upload';
        item.dataset.galleryItem = '';
        item.dataset.imageType = 'new';
        item.dataset.imageIndex = String(index);
        const image = document.createElement('img');
        image.src = previewUrl;
        image.alt = `Vista previa de ${file.name}`;
        image.loading = 'lazy';
        const position = document.createElement('span');
        position.className = 'product-gallery-position';
        const actions = document.createElement('div');
        actions.className = 'product-gallery-item-actions';
        ['up', 'down'].forEach(direction => {
          const button = document.createElement('button');
          button.type = 'button';
          button.dataset.galleryMove = direction;
          button.textContent = direction === 'up' ? '↑' : '↓';
          actions.append(button);
        });
        const orderInput = document.createElement('input');
        orderInput.type = 'hidden';
        orderInput.name = 'image_order[]';
        item.append(image, position, actions, orderInput);
        galleryItems.append(item);
      });

      updateGalleryOrder();
      const totalCount = existingCount + files.length;
      status.textContent = required
        ? `${files.length} de ${maxPhotos} fotos seleccionadas. Puedes cambiar el orden con las flechas.`
        : `${files.length} foto(s) nueva(s). Total: ${totalCount} de ${maxPhotos}. Puedes cambiar el orden con las flechas.`;
    });

    galleryItems.addEventListener('click', event => {
      if (!(event.target instanceof Element)) return;
      const button = event.target.closest('[data-gallery-move]');
      const item = button?.closest('[data-gallery-item]');
      if (!button || !item || button.disabled) return;

      if (button.dataset.galleryMove === 'up') {
        const previousItem = item.previousElementSibling;
        if (previousItem) galleryItems.insertBefore(item, previousItem);
      } else {
        const nextItem = item.nextElementSibling;
        if (nextItem) galleryItems.insertBefore(nextItem, item);
      }
      updateGalleryOrder();
    });

    updateGalleryOrder();
  });

  document.querySelectorAll('[data-image-upload]').forEach(upload => {
    const input = upload.querySelector('[data-image-input]');
    const preview = upload.querySelector('[data-image-preview]');
    const emptyState = upload.querySelector('[data-image-empty]');
    const fileName = upload.querySelector('[data-image-name]');
    const resetButton = upload.querySelector('[data-image-reset]');
    const initialSource = preview?.getAttribute('src') || '';
    let previewUrl = '';

    if (!input || !preview || !emptyState || !fileName || !resetButton) return;

    const showCurrentImage = () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = '';
      }

      if (initialSource) {
        preview.src = initialSource;
        preview.hidden = false;
        emptyState.hidden = true;
      } else {
        preview.removeAttribute('src');
        preview.hidden = true;
        emptyState.hidden = false;
      }

      fileName.textContent = initialSource ? 'Imagen actual' : 'Ninguna imagen seleccionada';
      resetButton.hidden = true;
    };

    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (!file) {
        showCurrentImage();
        return;
      }

      if (!ALLOWED_TYPES.has(file.type) || file.size > MAX_FILE_SIZE) {
        input.value = '';
        showCurrentImage();
        fileName.textContent = file.size > MAX_FILE_SIZE
          ? 'La imagen supera el límite de 5 MB.'
          : 'Formato no permitido. Usa JPG, PNG o WebP.';
        return;
      }

      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(file);
      preview.src = previewUrl;
      preview.hidden = false;
      emptyState.hidden = true;
      fileName.textContent = file.name;
      resetButton.hidden = false;
    });

    resetButton.addEventListener('click', () => {
      input.value = '';
      showCurrentImage();
    });

    preview.addEventListener('error', () => {
      if (!previewUrl) {
        preview.hidden = true;
        emptyState.hidden = false;
        fileName.textContent = 'No se pudo cargar la vista previa guardada.';
      }
    });
  });

  document.querySelectorAll('form').forEach(form => {
    if (form.querySelector('input[name="action"][value="save_banner"]') === null) return;

    const bannerCard = form.closest('.banner-admin-card');
    const imageInput = form.querySelector('input[name="image"]');
    const imagePreview = bannerCard?.querySelector('[data-banner-preview-image]');
    const textInput = form.querySelector('input[name="text"]');
    const textPreview = bannerCard?.querySelector('[data-banner-preview-text]');
    const resetButton = form.querySelector('[data-image-reset]');
    const initialSource = imagePreview?.getAttribute('src') || '';
    let previewUrl = '';

    if (!imageInput || !imagePreview || !textPreview) return;

    const updateTextPreview = () => {
      const text = textInput?.value.trim() || '';
      textPreview.textContent = text;
      textPreview.hidden = text === '';
    };

    imageInput.addEventListener('change', () => {
      const file = imageInput.files?.[0];
      if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
        || file.size > MAX_FILE_SIZE) return;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(file);
      imagePreview.src = previewUrl;
    });

    textInput?.addEventListener('input', updateTextPreview);
    resetButton?.addEventListener('click', () => {
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = '';
      }
      imagePreview.src = initialSource;
    });
  });

  document.querySelectorAll('.banner-edit-toggle').forEach(button => {
    const editDialog = document.getElementById(button.getAttribute('aria-controls') || '');
    if (!(editDialog instanceof HTMLDialogElement)) return;

    button.addEventListener('click', () => {
      editDialog.showModal();
      button.setAttribute('aria-expanded', 'true');
      editDialog.querySelector('input[name="text"]')?.focus();
    });

    editDialog.querySelectorAll('[data-close-banner-edit]').forEach(closeButton => {
      closeButton.addEventListener('click', () => editDialog.close());
    });
    editDialog.addEventListener('click', event => {
      if (event.target === editDialog) editDialog.close();
    });
    editDialog.addEventListener('close', () => {
      button.setAttribute('aria-expanded', 'false');
    });
  });

  const categoryDialog = document.getElementById('category-dialog');
  if (categoryDialog instanceof HTMLDialogElement) {
    document.querySelectorAll('[data-open-category-dialog]').forEach(button => {
      button.addEventListener('click', () => categoryDialog.showModal());
    });
    categoryDialog.querySelectorAll('[data-close-category-dialog]').forEach(button => {
      button.addEventListener('click', () => categoryDialog.close());
    });
    categoryDialog.addEventListener('click', event => {
      if (event.target === categoryDialog) categoryDialog.close();
    });
    if (categoryDialog.dataset.open === 'true') {
      categoryDialog.showModal();
      categoryDialog.querySelector('#category-name')?.focus();
    }
  }

  const productCreateDialog = document.getElementById('product-create-dialog');
  if (productCreateDialog instanceof HTMLDialogElement) {
    document.querySelectorAll('[data-open-product-create]').forEach(button => {
      button.addEventListener('click', () => {
        productCreateDialog.showModal();
        productCreateDialog.querySelector('#new-name')?.focus();
      });
    });
    productCreateDialog.querySelectorAll('[data-close-product-create]').forEach(button => {
      button.addEventListener('click', () => productCreateDialog.close());
    });
    productCreateDialog.addEventListener('click', event => {
      if (event.target === productCreateDialog) productCreateDialog.close();
    });
    if (productCreateDialog.dataset.open === 'true') {
      productCreateDialog.showModal();
      productCreateDialog.querySelector('#new-name')?.focus();
    }
  }

  const productImportDialog = document.getElementById('product-import-dialog');
  if (productImportDialog instanceof HTMLDialogElement) {
    const productAddDropdown = document.querySelector('.product-add-dropdown');
    const importForm = productImportDialog.querySelector('.product-import-form');

    importForm?.addEventListener('submit', () => {
      const submitButton = importForm.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Importando productos…';
        submitButton.setAttribute('aria-busy', 'true');
      }
    });

    document.addEventListener('click', event => {
      if (productAddDropdown instanceof HTMLDetailsElement
          && !productAddDropdown.contains(event.target)) {
        productAddDropdown.open = false;
      }
    });
    document.querySelectorAll('[data-open-product-import]').forEach(button => {
      button.addEventListener('click', () => {
        if (productAddDropdown instanceof HTMLDetailsElement) productAddDropdown.open = false;
        productImportDialog.showModal();
        productImportDialog.querySelector('#products-csv')?.focus();
      });
    });
    productImportDialog.querySelectorAll('[data-close-product-import]').forEach(button => {
      button.addEventListener('click', () => productImportDialog.close());
    });
    productImportDialog.addEventListener('click', event => {
      if (event.target === productImportDialog) productImportDialog.close();
    });
    if (productImportDialog.dataset.open === 'true') {
      productImportDialog.showModal();
      productImportDialog.querySelector('#products-csv')?.focus();
    }
  }

  const showAdminToast = (message, isError = false) => {
    document.querySelector('.admin-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `admin-toast${isError ? ' is-error' : ''}`;
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    toast.textContent = message;
    document.body.append(toast);
    window.setTimeout(() => toast.remove(), 5000);
  };

  const categoryList = document.querySelector('[data-category-list]');
  const productCategorySelects = document.querySelectorAll('select[name="category"]');
  const categoryCreateForm = document.querySelector('form input[name="action"][value="create_category"]')?.closest('form');
  const categoryCsrfToken = categoryCreateForm?.querySelector('input[name="csrf_token"]')?.value || '';

  const updateCategoryOptions = (categories, newKey = '') => {
    productCategorySelects.forEach(select => {
      const previousKey = select.value;
      select.replaceChildren();
      Object.entries(categories).forEach(([key, label]) => {
        const option = document.createElement('option');
        option.value = key;
        option.textContent = label;
        select.append(option);
      });
      select.value = select.id === 'new-category' && newKey
        ? newKey
        : Object.hasOwn(categories, previousKey) ? previousKey : (select.options[0]?.value || '');
    });
  };

  const renderCategoryList = categories => {
    if (!categoryList) return;
    const heading = categoryList.querySelector('h3');
    categoryList.replaceChildren();
    if (heading) categoryList.append(heading);
    const entries = Object.entries(categories);
    if (entries.length === 0) {
      const emptyMessage = document.createElement('p');
      emptyMessage.className = 'category-dialog-empty';
      emptyMessage.textContent = 'No hay categorías disponibles.';
      categoryList.append(emptyMessage);
      return;
    }

    entries.forEach(([key, label]) => {
      const item = document.createElement('div');
      item.className = 'category-dialog-item';
      const name = document.createElement('span');
      name.textContent = label;
      const form = document.createElement('form');
      form.method = 'post';
      form.dataset.categoryAction = '';
      form.dataset.confirm = `¿Eliminar la categoría ${label}? Solo se eliminará si no tiene productos asociados.`;
      [
        ['csrf_token', categoryCsrfToken],
        ['action', 'delete_category'],
        ['category_key', key],
        ['return_section', 'products']
      ].forEach(([fieldName, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = fieldName;
        input.value = value;
        form.append(input);
      });
      const button = document.createElement('button');
      button.type = 'submit';
      button.className = 'button-danger';
      button.textContent = 'Eliminar';
      form.append(button);
      item.append(name, form);
      categoryList.append(item);
    });
  };

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-category-action]')) return;
    if (form.querySelector('input[name="action"][value="delete_category"]')
      && form.dataset.confirmed !== 'true') return;

    event.preventDefault();
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;
    try {
      const response = await fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        cache: 'no-store'
      });
      const result = await response.json();
      if (!response.ok || result.success !== true) {
        throw new Error(result.error || 'No se pudo completar la acción de categoría.');
      }

      updateCategoryOptions(result.categories, result.created_key);
      renderCategoryList(result.categories);
      if (form.querySelector('input[name="action"][value="create_category"]')) {
        form.reset();
        form.querySelector('[data-image-input]')?.dispatchEvent(new Event('change'));
      }
      showAdminToast(result.message || 'Categorías actualizadas.');
    } catch (error) {
      showAdminToast(error instanceof Error ? error.message : 'No se pudo completar la acción de categoría.', true);
    } finally {
      form.dataset.confirmed = 'false';
      if (submitButton?.isConnected) submitButton.disabled = false;
    }
  });

  const inventoryToggle = document.querySelector('input[name="inventory_enabled"]');
  const inventoryThreshold = document.querySelector('[data-inventory-threshold]');
  const inventoryThresholdInput = inventoryThreshold?.querySelector('input[name="low_stock_threshold"]');
  const updateInventoryThresholdVisibility = () => {
    if (!inventoryToggle || !inventoryThreshold || !inventoryThresholdInput) return;
    inventoryThreshold.hidden = !inventoryToggle.checked;
    inventoryThresholdInput.disabled = !inventoryToggle.checked;
    inventoryThresholdInput.required = inventoryToggle.checked;
  };
  inventoryToggle?.addEventListener('change', updateInventoryThresholdVisibility);
  updateInventoryThresholdVisibility();

  const productSearch = document.getElementById('product-search');
  const productVisibilityFilter = document.getElementById('product-visibility-filter');
  const productCategoryFilter = document.getElementById('product-category-filter');
  const productCards = Array.from(document.querySelectorAll('.product-editor[data-product-name]'));
  const productCount = document.querySelector('[data-products-count]');
  const productFilterEmpty = document.querySelector('[data-product-filter-empty]');

  document.querySelectorAll('.product-edit-toggle').forEach(button => {
    const formArea = document.getElementById(button.getAttribute('aria-controls') || '');
    if (!formArea) return;

    button.addEventListener('click', () => {
      const expanded = button.getAttribute('aria-expanded') === 'true';
      button.setAttribute('aria-expanded', String(!expanded));
      formArea.hidden = expanded;
    });
  });

  const normalizeSearchText = value => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('es');

  const filterProducts = () => {
    if (!productSearch || !productVisibilityFilter || !productCategoryFilter) return;
    const searchTerm = normalizeSearchText(productSearch.value.trim());
    const visibility = productVisibilityFilter.value;
    const category = productCategoryFilter.value;
    let visibleCount = 0;

    productCards.forEach(card => {
      const isPublished = card.dataset.productActive === 'true';
      const matchesSearch = normalizeSearchText(card.dataset.productName || '').includes(searchTerm);
      const matchesVisibility = visibility === 'all'
        || (visibility === 'published' && isPublished)
        || (visibility === 'hidden' && !isPublished);
      const matchesCategory = category === 'all' || card.dataset.productCategory === category;
      const isVisible = matchesSearch && matchesVisibility && matchesCategory;
      card.hidden = !isVisible;
      if (isVisible) visibleCount++;
    });

    if (productCount) {
      const totalCount = Number.parseInt(productCount.dataset.total || '0', 10);
      productCount.textContent = visibleCount === totalCount
        ? `${totalCount} en total`
        : `${visibleCount} de ${totalCount} productos`;
    }
    if (productFilterEmpty) productFilterEmpty.hidden = visibleCount !== 0;
  };

  productSearch?.addEventListener('input', filterProducts);
  productVisibilityFilter?.addEventListener('change', filterProducts);
  productCategoryFilter?.addEventListener('change', filterProducts);

  const createUserForm = document.querySelector('input[name="action"][value="create_superadmin"]')?.closest('form');
  const usernameInput = createUserForm?.querySelector('#username');
  const usernameValidation = createUserForm?.querySelector('#username-validation');
  const passwordToggle = createUserForm?.querySelector('#toggle-password-fields');
  const passwordFields = createUserForm?.querySelector('#password-fields');

  if (createUserForm && usernameInput && usernameValidation) {
    const validateUsername = (showEmptyError = false) => {
      const value = usernameInput.value.trim();
      const isValid = /^[a-zA-Z0-9._-]{3,40}$/.test(value);
      const shouldShowError = !isValid && (value.length > 0 || showEmptyError);
      usernameValidation.hidden = !shouldShowError;
      usernameInput.setAttribute('aria-invalid', shouldShowError ? 'true' : 'false');
      usernameInput.setCustomValidity(shouldShowError ? usernameValidation.textContent.trim() : '');
      return isValid;
    };

    usernameInput.addEventListener('input', () => validateUsername());
    usernameInput.addEventListener('blur', () => validateUsername(true));
    usernameInput.addEventListener('invalid', () => validateUsername(true));
    createUserForm.addEventListener('submit', event => {
      if (!validateUsername(true)) {
        event.preventDefault();
        usernameInput.focus();
      }
    });
  }

  if (passwordToggle && passwordFields) {
    passwordToggle.addEventListener('click', () => {
      const isExpanded = passwordToggle.getAttribute('aria-expanded') === 'true';
      passwordToggle.setAttribute('aria-expanded', String(!isExpanded));
      passwordFields.hidden = isExpanded;
      passwordToggle.textContent = isExpanded
        ? 'Definir contraseña manualmente (opcional)'
        : 'Usar contraseña generada automáticamente';
    });

  }

  document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
    const input = toggle.parentElement?.querySelector('input[type="password"], input[type="text"]');
    if (!input) return;

    toggle.addEventListener('click', () => {
      const isVisible = input.type === 'text';
      input.type = isVisible ? 'password' : 'text';
      toggle.textContent = isVisible ? 'Mostrar' : 'Ocultar';
      toggle.setAttribute('aria-pressed', String(!isVisible));
      toggle.setAttribute('aria-label', `${isVisible ? 'Mostrar' : 'Ocultar'} ${input.labels?.[0]?.textContent.trim() || 'contraseña'}`);
    });
  });

  const userEditDialog = document.getElementById('user-edit-dialog');
  const editUserId = document.getElementById('edit-user-id');
  const editUsername = document.getElementById('edit-username');
  const editEmail = document.getElementById('edit-email');

  if (userEditDialog instanceof HTMLDialogElement && editUserId && editUsername && editEmail) {
    document.querySelectorAll('[data-edit-user]').forEach(button => {
      button.addEventListener('click', () => {
        editUserId.value = button.dataset.userId || '';
        editUsername.value = button.dataset.username || '';
        editEmail.value = button.dataset.email || '';
        userEditDialog.showModal();
        editUsername.focus();
      });
    });

    userEditDialog.querySelectorAll('[data-close-user-edit]').forEach(button => {
      button.addEventListener('click', () => userEditDialog.close());
    });
    userEditDialog.addEventListener('click', event => {
      if (event.target === userEditDialog) userEditDialog.close();
    });
  }

  const confirmationDialog = document.createElement('dialog');
  confirmationDialog.className = 'admin-confirmation-dialog';
  confirmationDialog.setAttribute('aria-labelledby', 'admin-confirmation-title');
  confirmationDialog.innerHTML = `
    <form method="dialog" class="admin-confirmation-card">
      <p class="eyebrow">CONFIRMACIÓN</p>
      <h2 id="admin-confirmation-title">¿Estás seguro?</h2>
      <p class="admin-confirmation-message"></p>
      <div class="admin-confirmation-actions">
        <button type="button" class="button-secondary" data-confirm-cancel>Cancelar</button>
        <button type="button" class="button-danger" data-confirm-accept>Confirmar</button>
      </div>
    </form>`;
  document.body.append(confirmationDialog);

  let pendingConfirmationForm = null;
  confirmationDialog.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => {
    confirmationDialog.close();
  });
  confirmationDialog.querySelector('[data-confirm-accept]')?.addEventListener('click', () => {
    if (!pendingConfirmationForm) return;
    const form = pendingConfirmationForm;
    pendingConfirmationForm = null;
    confirmationDialog.close();
    form.dataset.confirmed = 'true';
    form.requestSubmit();
  });
  confirmationDialog.addEventListener('close', () => {
    pendingConfirmationForm = null;
  });

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-confirm]')) return;
    if (form.dataset.confirmed === 'true') {
      delete form.dataset.confirmed;
      return;
    }
    if (typeof confirmationDialog.showModal !== 'function') return;
    event.preventDefault();
    pendingConfirmationForm = form;
    const message = confirmationDialog.querySelector('.admin-confirmation-message');
    if (message) message.textContent = form.dataset.confirm || 'Confirma esta acción para continuar.';
    confirmationDialog.showModal();
  });
})();
