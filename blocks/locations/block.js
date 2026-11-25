// Locales (bloque locations): el botón de llamar sigue el teléfono de cada local y «Cómo llegar» abre Google Maps con
// su dirección (sin mapa incrustado: no hace falta consentimiento de cookies)
(function () {
  document.querySelectorAll('.b-locations__item').forEach(function (item) {
    var phone = item.querySelector('[data-edit-field="phone"]'), address = item.querySelector('[data-edit-field="address"]');
    var call = item.querySelector('.b-locations__call'), map = item.querySelector('.b-locations__map');
    if (phone && call) {
      var digits = phone.textContent.replace(/[^\d+]/g, '');
      if (digits) call.href = 'tel:' + (digits.charAt(0) === '+' ? digits : '+34' + digits); else call.hidden = true;
    }
    if (address && map) {
      map.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address.textContent.replace(/\s*·\s*/g, ', ').trim());
    }
  });
})();
