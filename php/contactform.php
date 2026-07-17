<?php

 // ++++++++++++++++++++++++++++++++++++
 // Envia email através da API do SendGrid (HTTPS), em vez de SMTP.
 // Isto evita problemas de portas SMTP bloqueadas pelo hosting.
 // Requer apenas a extensão curl do PHP (praticamente sempre disponível).
 // ++++++++++++++++++++++++++++++++++++

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
http_response_code(200);

// ---------- logging ----------
$pasta_log = __DIR__ . "/contactform.log";
$log_escreveu = null;

function registar($linha) {
    global $pasta_log, $log_escreveu;
    $timestamp = date("Y-m-d H:i:s");
    $resultado = @file_put_contents($pasta_log, "[$timestamp] $linha\n", FILE_APPEND);
    $log_escreveu = ($resultado !== false);
}

registar("teste de arranque do script");

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        registar("ERRO FATAL: " . $erro['message'] . " em " . $erro['file'] . ":" . $erro['line']);
        if (!headers_sent()) {
            http_response_code(200);
            header("Content-Type: application/json; charset=UTF-8");
        }
        echo json_encode([
            "ok" => false,
            "erro_fatal" => $erro['message'],
            "ficheiro" => $erro['file'],
            "linha" => $erro['line'],
        ]);
    }
});

// ---------- configuração SendGrid ----------
$sendgrid_api_key = "AQUI_A_TUA_API_KEY"; // <-- substituir pela API key real (Settings > API Keys no SendGrid)
$email_remetente  = "segmoncoimbra@segmon.pt"; // tem de estar verificado no SendGrid (Single Sender ou domínio autenticado)
$nome_remetente   = "SEGMON — Sistemas Globais de Segurança";

// ---------- destinatários por departamento ----------
$email_por_departamento = array(
    "comercial"  => "bruno.amaro@segmon.pt,pgrilo@segmon.pt",       // Orçamento / Novo projeto
    "suporte"    => "suportetecnico@segmon.pt",                    // Suporte técnico
    "financeiro" => "segmoncoimbra@segmon.pt",                     // Financeiro / Faturação -> Administrativos
    "rh"         => "carlag@segmon.pt,catia.marques@segmon.pt",    // Recursos Humanos / Candidaturas
    "geral"      => "segmoncoimbra@segmon.pt",                     // Outro assunto -> Administrativos
);
$email_por_defeito = "segmoncoimbra@segmon.pt";

// ---------- verificação de versão (visita direta ao URL, fora do formulário) ----------
define('CONTACTFORM_VERSAO', 'sendgrid-v1');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "versao" => CONTACTFORM_VERSAO,
        "curl_disponivel" => function_exists('curl_init'),
        "log_escreveu" => $log_escreveu,
        "pasta_e_gravavel" => is_writable(__DIR__),
    ]);
    die();
}

// ---------- dados recebidos do formulário ----------
$rnd = isset($_POST['rnd']) ? $_POST['rnd'] : null;
$name = isset($_POST['name']) ? $_POST['name'] : null;
$email = isset($_POST['email']) ? $_POST['email'] : null;
$subject = isset($_POST['subject']) ? $_POST['subject'] : null;
$body = isset($_POST['body']) ? $_POST['body'] : null;
$department = isset($_POST['department']) ? $_POST['department'] : '';
$origem = isset($_POST['origem']) ? $_POST['origem'] : 'contacto'; // "contacto" ou "candidatura"

header("Content-Type: application/json; charset=UTF-8");

registar("---- novo pedido | department=$department | origem=$origem | email=$email ----");

if (!$rnd || !$name || !$email || !$subject || !$body) {
    registar("ERRO: campos em falta no POST.");
    echo json_encode(["ok" => false, "erro" => "Preencha os campos do formulário."]);
    die();
}

$department_key = strtolower(trim(stripslashes($department)));
$email_it_to = isset($email_por_departamento[$department_key])
    ? $email_por_departamento[$department_key]
    : $email_por_defeito;

$name_limpo = trim(preg_replace('/[\r\n]+/', ' ', stripslashes($name)));
$email_limpo = trim(preg_replace('/[\r\n]+/', ' ', stripslashes($email)));
$email_valido = filter_var($email_limpo, FILTER_VALIDATE_EMAIL);

$subject_interno = "[SEGMON.PT] " . stripslashes($subject);

$corpo_interno = "<p>Mensagem enviada por <strong>" . htmlspecialchars($name_limpo) . "</strong>, email: " . htmlspecialchars($email_limpo) . "</p>";
$corpo_interno .= "<p>Data: " . date("d/m/Y H:i") . "</p><hr>";
$corpo_interno .= "<p>" . nl2br(htmlspecialchars(stripslashes($body))) . "</p>";

// ---------- função auxiliar: envia um email via API do SendGrid ----------
function enviar_via_sendgrid($api_key, $de_email, $de_nome, $destinatarios, $assunto, $corpo_html, $reply_to = null) {
    $to = array();
    foreach (explode(',', $destinatarios) as $d) {
        $d = trim($d);
        if ($d !== '') {
            $to[] = ["email" => $d];
        }
    }

    $payload = [
        "personalizations" => [[
            "to" => $to,
        ]],
        "from" => ["email" => $de_email, "name" => $de_nome],
        "subject" => $assunto,
        "content" => [[
            "type" => "text/html",
            "value" => $corpo_html,
        ]],
    ];

    if ($reply_to) {
        $payload["reply_to"] = ["email" => $reply_to];
    }

    $ch = curl_init("https://api.sendgrid.com/v3/mail/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_key,
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    // força TLS 1.2 — corrige "SSL23_GET_SERVER_HELLO: tlsv1 alert protocol version"
    // em servidores com curl/OpenSSL antigos que tentam negociar SSL/TLS desatualizados
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    // FIX SSL: desativa verificação temporariamente (certificado CA do servidor desatualizado)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $resposta = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro_curl = curl_error($ch);
    // curl_close() removido — deprecated no PHP 8.5 (libertado automaticamente)

    // SendGrid devolve 202 quando aceita o email para envio
    $sucesso = ($codigo_http === 202);

    return [
        "sucesso" => $sucesso,
        "codigo_http" => $codigo_http,
        "resposta" => $resposta,
        "erro_curl" => $erro_curl,
    ];
}

// ---------- 1) email interno para a equipa SEGMON ----------
$resultado_interno = enviar_via_sendgrid(
    $sendgrid_api_key,
    $email_remetente,
    $nome_remetente,
    $email_it_to,
    $subject_interno,
    $corpo_interno,
    $email_valido ? $email_limpo : null
);

if ($resultado_interno["sucesso"]) {
    registar("Email interno enviado com sucesso para: $email_it_to");
} else {
    registar("ERRO ao enviar email interno | HTTP {$resultado_interno['codigo_http']} | curl: {$resultado_interno['erro_curl']} | resposta SendGrid: {$resultado_interno['resposta']}");
}

// ---------- 2) resposta automática para quem preencheu o formulário ----------
$resultado_auto = ["sucesso" => false, "codigo_http" => null, "resposta" => null, "erro_curl" => null];
if ($email_valido) {
    if ($origem === "candidatura") {
        $assunto_auto = "Recebemos a sua candidatura — SEGMON";
        $corpo_auto  = "<p>Olá " . htmlspecialchars($name_limpo) . ",</p>";
        $corpo_auto .= "<p>Obrigado pelo interesse em fazer parte da equipa SEGMON. ";
        $corpo_auto .= "A sua candidatura foi recebida com sucesso e vai ser analisada pela nossa equipa de Recursos Humanos.</p>";
        $corpo_auto .= "<p>Se o seu perfil corresponder a uma oportunidade atual ou futura, entraremos em contacto consigo brevemente.</p>";
        $corpo_auto .= "<p>Com os melhores cumprimentos,<br>Equipa SEGMON</p>";
    } else {
        $assunto_auto = "Recebemos a sua mensagem — SEGMON";
        $corpo_auto  = "<p>Olá " . htmlspecialchars($name_limpo) . ",</p>";
        $corpo_auto .= "<p>Obrigado por nos contactar. A sua mensagem foi recebida com sucesso ";
        $corpo_auto .= "e vamos entrar em contacto consigo brevemente.</p>";
        $corpo_auto .= "<p>Com os melhores cumprimentos,<br>Equipa SEGMON</p>";
    }

    $resultado_auto = enviar_via_sendgrid(
        $sendgrid_api_key,
        $email_remetente,
        $nome_remetente,
        $email_limpo,
        $assunto_auto,
        $corpo_auto
    );

    if ($resultado_auto["sucesso"]) {
        registar("Autoresposta enviada com sucesso para: $email_limpo");
    } else {
        registar("ERRO ao enviar autoresposta | HTTP {$resultado_auto['codigo_http']} | curl: {$resultado_auto['erro_curl']} | resposta SendGrid: {$resultado_auto['resposta']}");
    }
} else {
    registar("Email do remetente inválido ('$email_limpo') — autoresposta não foi tentada.");
}

echo json_encode([
    "ok" => $resultado_interno["sucesso"],
    "interno_enviado" => $resultado_interno["sucesso"],
    "autoresposta_enviada" => $resultado_auto["sucesso"],
    "destino" => $email_it_to,
    "detalhe_interno" => $resultado_interno,
    "detalhe_autoresposta" => $resultado_auto,
    "log_escreveu" => $log_escreveu,
]);

?>
