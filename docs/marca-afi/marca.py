# Marca d'AFI para los documentos que se entregan al cliente (guía de edición y los que vengan), según la plantilla
# de documentos de AFI (Asteroid #4460):
#
#   - portada: arriba, en una franja blanca, el logo grande y las direcciones de AFI (portada_html + PORTADA_CSS).
#   - páginas interiores: cabecera con «afi Informàtica» a la izquierda, una línea azul (el de la marca, no la roja de
#     la plantilla) y el logo pequeño a la derecha, debajo de la línea; pie con la línea azul y el logo en el centro.
#
# Los documentos se imprimen con Chromium (Page.printToPDF): parametres_pdf() da los parámetros de la cabecera y el pie
# (headerTemplate/footerTemplate, que Chromium pinta en el margen de cada página). Una página con margen 0 (la portada,
# con «@page :first { margin: 0 }») no tiene sitio para ellos y sale sin cabecera ni pie.
#
# El documento tiene que dejar los márgenes de MARGES en su @page (ver MARGE_CSS) para que quepan.
import base64
from pathlib import Path

AQUI = Path(__file__).resolve().parent
LOGO_H = AQUI / 'logo-horitzontal.png'       # «afi | informàtica» en una línea (cabeceras y pies)
LOGO = AQUI / 'logo.png'                     # «afi» con «informàtica» debajo (portada)

NOM = 'afi Informàtica'
ADRECES = ['Vilabella, 5-7 – 08500 VIC', 'Indústria, 30 (P.I. La Quintana) – 08504 ST. JULIÀ']
WEB = 'www.afi.cat'
BLAU = '#267ebc'                             # el azul central del logo
GRIS = '#404040'

# Márgenes de las páginas interiores (arriba, derecha, abajo, izquierda), en mm: la cabecera y el pie van dentro.
MARGES = (28, 19, 23, 19)
MARGE_CSS = '%smm %smm %smm %smm' % MARGES


def _data_uri(p: Path) -> str:
    return 'data:image/png;base64,' + base64.b64encode(p.read_bytes()).decode()


def _plantilla(cos: str) -> str:
    # Las plantillas de Chromium no heredan nada del documento: todo en línea, y el tamaño de letra hay que darlo.
    return ('<div style="width:100%%; padding:0 %smm 0 %smm; box-sizing:border-box; font-family:Arial, Helvetica, '
            'sans-serif; -webkit-print-color-adjust:exact;">%s</div>' % (MARGES[3], MARGES[1], cos))


def capcalera() -> str:
    return _plantilla(
        '<div style="font-size:9pt; font-weight:700; color:%s; padding-bottom:1mm; border-bottom:0.5mm solid %s;">%s</div>'
        '<div style="text-align:right; margin-top:1mm;"><img src="%s" style="height:4.2mm;"></div>'
        % (GRIS, BLAU, NOM, _data_uri(LOGO_H)))


def peu() -> str:
    return _plantilla(
        '<div style="border-top:0.5mm solid %s; padding-top:1.5mm; text-align:center;"><img src="%s" style="height:4.2mm;"></div>'
        % (BLAU, _data_uri(LOGO_H)))


def parametres_pdf() -> dict:
    """Parámetros de Page.printToPDF para la cabecera y el pie de AFI (con preferCSSPageSize, los márgenes son los del @page)."""
    return {'displayHeaderFooter': True, 'headerTemplate': capcalera(), 'footerTemplate': peu()}


def portada_html(src_logo: str = 'afi-logo.png') -> str:
    """Cabecera de la portada: el logo y las direcciones de AFI en una franja blanca. src_logo: dónde se ha copiado LOGO."""
    adreces = '<br>'.join(ADRECES)
    return ('<div class="afi-portada"><img src="%s" alt="%s"><div class="afi-dades">%s<br><span>%s</span></div></div>'
            % (src_logo, NOM, adreces, WEB))


# Para el <style> del documento. La franja va al principio de la portada y ocupa todo el ancho de la página: el contenedor
# de la portada tiene que dejar que se salga de su padding (ver el margin negativo en la guía) o no tener padding.
PORTADA_CSS = '''
.afi-portada { display: flex; align-items: center; justify-content: space-between; background: #fff; color: %(gris)s;
  border-bottom: 0.6mm solid %(blau)s; padding: 9mm 24mm 7mm; }
.afi-portada img { height: 17mm; display: block; }
.afi-dades { font: 700 8pt/1.5 Arial, Helvetica, sans-serif; text-align: right; color: %(gris)s; }
.afi-dades span { display: inline-block; margin-top: 1.5mm; }
''' % {'gris': GRIS, 'blau': BLAU}
