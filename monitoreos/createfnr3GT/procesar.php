<?php
// Configuración inicial
ini_set('max_execution_time', 0);
date_default_timezone_set('America/Guatemala');

// Configuración de buffers para salida en tiempo real
header('Content-Type: text/html; charset=UTF-8');
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

// Función para timestamp detallado
function timestamp() {
    $microtime = explode(' ', microtime());
    $milis = str_pad((int)($microtime[0] * 1000), 3, '0', STR_PAD_LEFT);
    return "[" . date('Y-m-d H:i:s') . ".$milis]";
}

// Función para enviar mensajes al frontend
function enviarMensaje($mensaje) {
    $mensaje = str_replace(["\n", "\r", "'"], ['\\n', '', "\\'"], $mensaje);
    $mensaje = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo "<script>"
         . "if(parent && parent.actualizarConsola) parent.actualizarConsola('$mensaje');"
         . "</script>\n";
    ob_flush();
    flush();
}

// Función para actualizar progreso
function actualizarProgreso($progreso, $enviados, $total) {
    echo "<script>"
         . "if(parent && parent.actualizarBarraEnvio) parent.actualizarBarraEnvio($progreso);"
         . "if(parent && parent.actualizarContadores) parent.actualizarContadores($enviados, $total);"
         . "</script>\n";
    ob_flush();
    flush();
}

// Función para generar XML
function generarXML($user, $pass, $numero) {
    return '<?xml version="1.0" encoding="ISO-8859-1"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:add="http://www.w3.org/2005/08/addressing"
                  xmlns:amx="http://schemas.ericsson.com/ma/CA/AMXCENAMMobilePrepaidGT_HW_vUDB/"
                  xmlns:asy="http://schemas.ericsson.com/async/"
                  xmlns:cai3="http://schemas.ericsson.com/cai3g1.2/">
  <soapenv:Header>
    <add:Security>
      <add:UsernameToken>
        <add:Username>' . htmlspecialchars($user, ENT_QUOTES) . '</add:Username>
        <add:Password>' . htmlspecialchars($pass, ENT_QUOTES) . '</add:Password>
      </add:UsernameToken>
    </add:Security>
    <cai3:Context>' . htmlspecialchars($numero, ENT_QUOTES) . '</cai3:Context>
  </soapenv:Header>
  <soapenv:Body>
    <cai3:Set>
      <cai3:MOType>PrepaidMobile@http://schemas.ericsson.com/ma/CA/AMXCENAMMobilePrepaidGT_HW_vUDB/</cai3:MOType>
      <cai3:MOId>
        <cai3:OrderId>' . $numero . '</cai3:OrderId>
        <amx:msisdn>' . $numero . '</amx:msisdn>
      </cai3:MOId>
      <cai3:MOAttributes>
        <amx:SetPrepaidMobile msisdn="' . $numero . '">
          <amx:Usecase>CREATE_FNR_3</amx:Usecase>
          <amx:msisdn>' . $numero . '</amx:msisdn>
          <amx:WFC/>
        </amx:SetPrepaidMobile>
      </cai3:MOAttributes>
    </cai3:Set>
  </soapenv:Body>
</soapenv:Envelope>';
}

// Función para enviar solicitud SOAP
function enviarXML($url, $xml) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: text/xml; charset=ISO-8859-1",
        "Content-Length: " . strlen($xml)
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $response = "❌ cURL Error: " . curl_error($ch);
    }
    curl_close($ch);
    return $response;
}

// Procesar solicitud POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario    = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $contrasena = isset($_POST['contrasena']) ? trim($_POST['contrasena']) : '';
    $pais       = isset($_POST['pais']) ? trim($_POST['pais']) : 'Guatemala';
    
    // Validación del delay con valores predefinidos
    $delay = isset($_POST['delay']) ? intval($_POST['delay']) : 3000;
    $allowed_delays = array(1000, 2000, 3000, 5000);
    if (!in_array($delay, $allowed_delays, true)) {
        $delay = 3000; // Valor por defecto si no es válido
    }
    
    $endpoint   = isset($_POST['endpoint']) ? trim($_POST['endpoint']) : '';

    if (!is_dir('logs')) mkdir('logs', 0755, true);
    $logFile = 'logs/log_' . date('Ymd_His') . '.txt';
    $log = fopen($logFile, 'w');

    $total = 0; $enviados = 0; $omitidos = 0; $errores = 0;

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === 0) {
        $archivo = $_FILES['archivo']['tmp_name'];
        $datos = array_map('str_getcsv', file($archivo));
        $primeraFila = array_map('trim', $datos[0]);

        if (count($primeraFila) === 1 && is_numeric($primeraFila[0])) {
            $sinEncabezado = true;
            $indice_numero = 0;
        } else {
            $encabezado = array_map('strtolower', $primeraFila);
            $indice_numero = false;
            foreach ($encabezado as $i => $col) {
                if (in_array($col, ['numero', 'macaddress', 'msisdn'])) {
                    $indice_numero = $i;
                    break;
                }
            }
            if ($indice_numero === false) {
                enviarMensaje('❌ No se encontró ninguna columna válida (numero, macaddress o msisdn).');
                fclose($log);
                exit;
            }
            array_shift($datos);
        }

        @unlink("detener.txt");
        @unlink("pausar.txt");

        enviarMensaje('🔄 Inicio: ' . date('Y-m-d H:i:s'));
        enviarMensaje('🌍 País: ' . $pais);
        enviarMensaje('📍 Endpoint: ' . $endpoint);
        enviarMensaje('👤 Usuario: ' . $usuario);
        enviarMensaje('🕒 Delay: ' . $delay . ' ms');
        enviarMensaje('');

        fwrite($log, timestamp() . " Inicio del envío\n");
        fwrite($log, "País: $pais\nEndpoint: $endpoint\nUsuario: $usuario\nDelay: {$delay} ms\n\n");

        $numRegistros = count($datos);
        actualizarProgreso(0, 0, $numRegistros);

        for ($i = 0; $i < $numRegistros; $i++) {
            if (file_exists("detener.txt")) {
                enviarMensaje('🛑 Envío detenido por el usuario');
                fwrite($log, timestamp() . " Proceso detenido manualmente\n");
                break;
            }
            while (file_exists("pausar.txt")) {
                enviarMensaje('⏸ Envío pausado...');
                sleep(1);
            }

            $fila = $datos[$i];
            $total++;

            if (!isset($fila[$indice_numero])) {
                enviarMensaje("⚠️ [$i] Saltado: número no definido");
                fwrite($log, timestamp() . " [$i] Saltado: número no definido\n");
                $omitidos++;
                continue;
            }

            $numero = trim($fila[$indice_numero]);
            if ($numero === '') {
                enviarMensaje("⚠️ [$i] Saltado: número vacío");
                fwrite($log, timestamp() . " [$i] Saltado: número vacío\n");
                $omitidos++;
                continue;
            }

            $xml = generarXML($usuario, $contrasena, $numero);
            fwrite($log, timestamp() . " [$i] XML generado:\n" . $xml . "\n\n");

            enviarMensaje('➡️ [' . $i . '] Enviando número: msisdn=' . $numero . ' - ' . timestamp());

            $inicio = microtime(true);
            $respuesta = enviarXML($endpoint, $xml);
            $duracion = round(microtime(true) - $inicio, 2);

            if (strpos($respuesta, 'Fault') !== false || strpos($respuesta, 'error') !== false) {
                $errores++;
                enviarMensaje('❌ [' . $i . '] Error en respuesta - ' . timestamp());
                fwrite($log, timestamp() . " [$i] Error en respuesta\n$respuesta\n\n");
            } else {
                $enviados++;
                enviarMensaje('✅ [' . $i . '] Envío exitoso - Duración: ' . $duracion . 's - ' . timestamp());
                fwrite($log, timestamp() . " [$i] Envío exitoso - Duración: {$duracion}s\n\n");
            }

            fwrite($log, "----------------------------------------\n\n");

            $progreso = round((($i+1) / $numRegistros) * 100);
            actualizarProgreso($progreso, $enviados, $numRegistros);

            usleep($delay * 1000);
        }

        enviarMensaje("\n🎯 Resumen:");
        enviarMensaje("📄 Total registros: " . $total);
        enviarMensaje("📤 Enviados OK: " . $enviados);
        enviarMensaje("⚠️ Omitidos: " . $omitidos);
        enviarMensaje("❌ Errores: " . $errores);
        enviarMensaje("🕓 Fin: " . date('Y-m-d H:i:s'));
        enviarMensaje('📁 <a href="' . $logFile . '" target="_blank">Descargar log completo</a>');

        fwrite($log, timestamp() . " Resumen del proceso:\n");
        fwrite($log, "Total registros: $total\n");
        fwrite($log, "Enviados exitosos: $enviados\n");
        fwrite($log, "Registros omitidos: $omitidos\n");
        fwrite($log, "Errores detectados: $errores\n");
        fwrite($log, timestamp() . " Proceso finalizado\n");
        fclose($log);

        echo "<script>"
           . "if(parent && parent.finalizarBarra) parent.finalizarBarra();"
           . "if(parent && parent.actualizarLogFileName) parent.actualizarLogFileName('$logFile');"
           . "</script>";
    } else {
        enviarMensaje('❌ Error al subir el archivo');
        echo "<script>if(parent && parent.showToast) parent.showToast('Error al subir el archivo', 'error');</script>";
    }
}

ob_flush();
flush();
exit;
?>