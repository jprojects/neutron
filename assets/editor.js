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
    });

    document.querySelectorAll('[data-edit-img]').forEach(makeImageSwappable);

    document.querySelectorAll('[data-edit-collection]').forEach(function (container) {
      container.classList.add('neutron-collection');
      container.querySelectorAll('[data-edit-item]').forEach(function (item) {
        bindItem(item, container);
      });
      addCollectionControls(container);
    });

    bar.addEventListener('click', function (e) {
      var action = e.target.getAttribute('data-action');
      if (action === 'save') doSave();
      if (action === 'exit') exitAdminMode();
      if (action === 'logout') doLogout();
    });

    function bindItem(item, container) {
      item.querySelectorAll('[data-edit-field]').forEach(function (field) {
        if (field.tagName.toLowerCase() === 'img') {
          makeImageSwappable(field);
        } else {
          field.setAttribute('contenteditable', 'true');
          field.classList.add('neutron-editable');
        }
      });
      addRemoveButton(item, container);
    }

    function addRemoveButton(item, container) {
      if (item.querySelector('.neutron-remove-item')) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'neutron-remove-item';
      btn.textContent = '✕';
      btn.addEventListener('click', function () {
        if (container.querySelectorAll('[data-edit-item]').length <= 1) {
          alert('Debe quedar al menos un elemento.');
          return;
        }
        item.remove();
      });
      item.appendChild(btn);
    }

    function addCollectionControls(container) {
      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'neutron-add-item';
      addBtn.textContent = '+ Agregar elemento';
      addBtn.addEventListener('click', function () {
        var items = container.querySelectorAll('[data-edit-item]');
        var last = items[items.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        var existingRemove = clone.querySelector('.neutron-remove-item');
        if (existingRemove) existingRemove.remove();
        clone.querySelectorAll('[data-edit-field]').forEach(function (field) {
          if (field.tagName.toLowerCase() === 'img') {
            field.removeAttribute('src');
          } else {
            field.textContent = '';
          }
        });
        container.insertBefore(clone, addBtn);
        bindItem(clone, container);
      });
      container.appendChild(addBtn);
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
      document.querySelectorAll('[data-edit-collection]').forEach(function (container) {
        var key = container.getAttribute('data-edit-collection');
        var items = [];
        container.querySelectorAll('[data-edit-item]').forEach(function (item) {
          var data = {};
          item.querySelectorAll('[data-edit-field]').forEach(function (field) {
            var fkey = field.getAttribute('data-edit-field');
            if (field.tagName.toLowerCase() === 'img') {
              data[fkey] = field.getAttribute('src') || '';
            } else {
              data[fkey] = field.textContent.trim();
            }
          });
          items.push(data);
        });
        collections[key] = items;
      });

      return { fields: fields, collections: collections };
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
