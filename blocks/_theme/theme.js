/* Base de los bloques: menú del móvil, sombra de la cabecera y enlaces de teléfono y correo que siguen el texto editable */
(function () {
  var isAdmin = !!document.getElementById('neutron-config');
  if (isAdmin) document.documentElement.classList.add('is-admin');
  var header = document.querySelector('.site-header');
  var toggle = document.querySelector('.b-header__toggle');
  var nav = document.getElementById('nav');
  function setMenu(open) {
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (toggle && nav) {
    toggle.addEventListener('click', function () { setMenu(!nav.classList.contains('is-open')); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a') && !isAdmin) setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('is-open')) setMenu(false); });
  }
  function onScroll() { if (header) header.classList.toggle('is-scrolled', window.scrollY > 10); }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  var phone = document.querySelector('[data-edit="phone"]');
  if (phone) {
    var digits = phone.textContent.replace(/[^\d+]/g, '');
    document.querySelectorAll('.js-phone').forEach(function (a) { a.href = 'tel:' + (digits.charAt(0) === '+' ? digits : '+34' + digits); });
  }
  var email = document.querySelector('[data-edit="email"]');
  if (email) document.querySelectorAll('.js-email').forEach(function (a) { a.href = 'mailto:' + email.textContent.trim(); });
})();
