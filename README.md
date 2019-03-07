# Integración OSCommerce-Khipu

## Usar khipu como medio de pago

Esta extensión ofrece integración del sistema de e-commerce [OSCommerce](http://oscommerce.com/) con [khipu](https://khipu.com).
Al instalarlo permite a los clientes pagar usando *Transferencia simplificada* (usando el terminal de pago) o con *Transferencia electrónica normal*.

## Requisitos

Esta extensión es compatible con [OSCommerce](http://oscommerce.com/) 2.3.x. OSCommerce 3.0 aún es una versión de desarrollo y no existe soporte oficial de khipu. 

## Instalación

Puedes revisar una [guía online](https://khipu.com/page/oscommerce) de como instalar esta extensión.

Se debes descomprimir el archivo .zip dentro del directorio *catalog* de tu instalación de OSCommerce. Con esto el módulo estará disponible para
activarlo.
  
Luego, en la de administración de OSCommerce debes ir a módulos y luego a módulos de pago. Debes activer el nuevo módulo "Transferencia bancaria".
  
Finalmente, dentro de la configuración del módulo, se deben incluir el _ID de cobrador_ y la _llave de cobrador_ o _secret_ (obtenibles desde https://khipu.com en Opciones de la cuenta de cobro).

## Como reportar problemas o ayudar al desarrollo

El sitio oficial de esta extensión es su [página en github.com](https://github.com/khipu/oscommerce-khipu). Si deseas informar de errores, revisar el código fuente o ayudarnos a mejorarla puedes usar el sistema de tickets y pull-requests. Toda ayuda es bienvenida.

## Empaquetar la extensión

Se incluye un shell-script  de linux para empaquetar esta extensión. Debes ejecutar:

$ ./build.sh

## Licencia GPL

Esta extensión se distribuye bajo los términos de la licencia GPL versión 3. Puedes leer el archivo license.txt con los detalles de la licencia.

