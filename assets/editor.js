(function () {
  var cfgEl = document.getElementById('neutron-config');
  if (!cfgEl) return;

  var cfg = JSON.parse(cfgEl.textContent || '{}');

  if (!cfg.loggedIn) {
    showLoginModal(cfg.csrf);
    return;
  }

  initEditor(cfg);

  function showLoginModal(csrf) {
    var overlay = document.createElement('div');
    overlay.className = 'neutron-overlay';
    overlay.innerHTML =
      '<form class="neutron-login-box">' +
        '<h2>Acceso administrador</h2>' +
        '<input type="password" name="password" placeholder="Contraseña" autofocus required>' +
        '<button type="submit">Entrar</button>' +
        '<p class="neutron-error" hidden></p>' +
      '</form>';
    document.body.appendChild(overlay);

    var form = overlay.querySelector('form');
    var errorEl = overlay.querySelector('.neutron-error');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var password = form.password.value;
      fetch('admin/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ password: password })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            location.reload();
          } else {
            errorEl.textContent = data.error || 'Error de acceso';
            errorEl.hidden = false;
          }
        })
        .catch(function () {
          errorEl.textContent = 'Error de red';
          errorEl.hidden = false;
        });
    });
  }

  function initEditor(cfg) {
    document.body.classList.add('neutron-editing');

    var bar = document.createElement('div');
    bar.className = 'neutron-bar';
    bar.innerHTML =
      '<span>Modo edición</span>' +
      '<button type="button" data-action="save">Guardar cambios</button>' +
      '<button type="button" data-action="exit">Salir</button>' +
      '<button type="button" data-action="logout">Cerrar sesión</button>' +
      '<span class="neutron-status"></span>';
    document.body.appendChild(bar);

    var statusEl = bar.querySelector('.neutron-status');

    document.querySelectorAll('[data-edit]').forEach(function (el) {
      el.setAttribute('contenteditable', 'true');
      el.classList.add('neutron-editable');
      // La misma clave puede salir varias veces (cabecera y pie): se mantienen iguales
      el.addEventListener('input', function () {
        var key = el.getAttribute('data-edit');
        document.querySelectorAll('[data-edit]').forEach(function (other) {
          if (other !== el && other.getAttribute('data-edit') === key) other.textContent = el.textContent;
        });
      });
    });

    document.querySelectorAll('[data-edit-img]').forEach(makeImageSwappable);

    // Listas: cada campo o sublista pertenece al data-edit-item más cercano, así puede haber
    // listas dentro de listas (secciones de una carta con sus platos).
    topCollections().forEach(setupCollection);

    bar.addEventListener('click', function (e) {
      var action = e.target.getAttribute('data-action');
      if (action === 'save') doSave();
      if (action === 'exit') exitAdminMode();
      if (action === 'logout') doLogout();
    });

    function ownerItem(el) {
      return el.parentElement ? el.parentElement.closest('[data-edit-item]') : null;
    }
    function topCollections() {
      return Array.prototype.filter.call(document.querySelectorAll('[data-edit-collection]'), function (c) { return !ownerItem(c); });
    }
    function ownItems(container) {
      return Array.prototype.filter.call(container.children, function (c) { return c.hasAttribute('data-edit-item'); });
    }
    function own(item, selector) {
      return Array.prototype.filter.call(item.querySelectorAll(selector), function (el) { return ownerItem(el) === item; });
    }

    function setupCollection(container) {
      container.classList.add('neutron-collection');
      ownItems(container).forEach(function (item) { bindItem(item, container); });
      addCollectionControls(container);
    }

    function bindItem(item, container) {
      own(item, '[data-edit-field]').forEach(function (field) {
        if (field.tagName.toLowerCase() === 'img') {
          makeImageSwappable(field);
        } else {
          field.setAttribute('contenteditable', 'true');
          field.classList.add('neutron-editable');
        }
      });
      own(item, '[data-edit-collection]').forEach(setupCollection);
      addItemControls(item, container);
    }

    function addItemControls(item, container) {
      var box = document.createElement('span');
      box.className = 'neutron-item-controls';
      box.innerHTML =
        '<button type="button" class="neutron-move-item" title="Subir">↑</button>' +
        '<button type="button" class="neutron-remove-item" title="Quitar">✕</button>';
      box.querySelector('.neutron-move-item').addEventListener('click', function () {
        var items = ownItems(container);
        var i = items.indexOf(item);
        if (i > 0) container.insertBefore(item, items[i - 1]);
      });
      box.querySelector('.neutron-remove-item').addEventListener('click', function () {
        if (ownItems(container).length <= 1) {
          alert('Debe quedar al menos un elemento.');
          return;
        }
        item.remove();
      });
      item.appendChild(box);
    }

    function addCollectionControls(container) {
      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'neutron-add-item';
      addBtn.textContent = '+ ' + (container.getAttribute('data-edit-add') || 'Agregar elemento');
      addBtn.addEventListener('click', function () {
        var items = ownItems(container);
        var last = items[items.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        // Sin los controles del editor; las sublistas se quedan con un solo elemento vacío
        clone.querySelectorAll('.neutron-item-controls, .neutron-add-item').forEach(function (el) { el.remove(); });
        clone.querySelectorAll('[data-edit-collection]').forEach(function (sub) {
          ownItems(sub).slice(1).forEach(function (extra) { extra.remove(); });
        });
        clone.querySelectorAll('[data-edit-field]').forEach(function (field) {
          if (field.tagName.toLowerCase() !== 'img') field.textContent = '';
        });
        container.insertBefore(clone, last.nextSibling);
        bindItem(clone, container);
        var first = clone.querySelector('[contenteditable]');
        if (first) first.focus();
      });
      // En listas (ul/ol/tbody) el botón va después, para no meter un <button> entre los <li>
      if (/^(UL|OL|TBODY|TABLE|DL|SELECT)$/.test(container.tagName)) {
        container.parentNode.insertBefore(addBtn, container.nextSibling);
      } else {
        container.appendChild(addBtn);
      }
    }

    function makeImageSwappable(img) {
      img.classList.add('neutron-editable-img');
      img.addEventListener('click', function () {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/png,image/jpeg,image/gif,image/webp';
        input.addEventListener('change', function () {
          if (!input.files || !input.files[0]) return;
          uploadImage(input.files[0], img);
        });
        input.click();
      });
    }

    function uploadImage(file, imgEl) {
      var fd = new FormData();
      fd.append('image', file);
      statusEl.textContent = 'Subiendo imagen...';
      fetch('admin/upload.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrf },
        body: fd
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            imgEl.src = data.url;
            statusEl.textContent = 'Imagen lista (recordá guardar)';
          } else {
            statusEl.textContent = 'Error: ' + (data.error || 'no se pudo subir');
          }
        })
        .catch(function () {
          statusEl.textContent = 'Error de red al subir';
        });
    }

    function collectPayload() {
      var fields = {};
      document.querySelectorAll('[data-edit]').forEach(function (el) {
        fields[el.getAttribute('data-edit')] = el.textContent.trim();
      });

      document.querySelectorAll('[data-edit-img]').forEach(function (el) {
        fields[el.getAttribute('data-edit-img')] = el.getAttribute('src');
      });

      var collections = {};
      topCollections().forEach(function (container) {
        collections[container.getAttribute('data-edit-collection')] = collectItems(container);
      });

      return { fields: fields, collections: collections };
    }

    function collectItems(container) {
      return ownItems(container).map(function (item) {
        var data = {};
        own(item, '[data-edit-field]').forEach(function (field) {
          var fkey = field.getAttribute('data-edit-field');
          data[fkey] = field.tagName.toLowerCase() === 'img' ? (field.getAttribute('src') || '') : field.textContent.trim();
        });
        own(item, '[data-edit-collection]').forEach(function (sub) {
          data[sub.getAttribute('data-edit-collection')] = collectItems(sub);
        });
        return data;
      });
    }

    function doSave() {
      statusEl.textContent = 'Guardando...';
      fetch('admin/save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body: JSON.stringify(collectPayload())
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          statusEl.textContent = data.ok ? 'Guardado ✓' : 'Error: ' + (data.error || '');
        })
        .catch(function () {
          statusEl.textContent = 'Error de red al guardar';
        });
    }

    function exitAdminMode() {
      var url = new URL(location.href);
      url.searchParams.delete('admin');
      location.href = url.toString();
    }

    function doLogout() {
      fetch('admin/logout.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrf }
      }).then(function () {
        location.reload();
      });
    }
  }
})();
