

<?php
/**
 * Plugin Name:       Benim Yurtiçi Kargo Entegrasyonum (Nihai Sürüm - Kendi Kendine Yeterli)
 * Description:       Harici dosya gerektirmez. Gönderi oluşturur, barkod gösterir ve kargo durumlarını kontrol eder.
 * Version:           6.0 - Self-Contained
 * Author:            [Ferdi arıkan]
 */

// Güvenlik: Betiğin doğrudan çalıştırılmasını engelle
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// ===================================================================================
// BÖLÜM 1: OTOMATİK GÖNDERİ OLUŞTURMA (GÜVENLİ VERİ GÖNDERİMİ İLE)
// ===================================================================================
add_action( 'woocommerce_order_status_changed', 'yk_api_gonderi_olustur_v8', 20, 4 );

function yk_api_gonderi_olustur_v8( $order_id, $status_from, $status_to, $order ) {
    if ( 'kargoya-hazir' !== $status_to ) { return; }
    if ( function_exists('ast_get_tracking_items') && ast_get_tracking_items( $order_id ) ) { return; }

    // --- API AYARLARI ---
   $api_url = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices?wsdl';
    $ws_user_name = 'YKTEST'; $ws_password = 'YK';

    $unique_cargo_key = 'WC-' . $order_id;

    // ADIM 1: Gönderiyi oluştur ve sisteme kaydet
    // =================================================
    
    // --- VERİYİ GÜVENLİ HALE GETİRME (SANITIZATION) ---
    // Müşteri verilerini XML için güvenli hale getiriyoruz. Bu, '&' gibi özel karakter hatalarını çözer.
    $receiver_name    = htmlspecialchars($order->get_formatted_shipping_full_name(), ENT_XML1, 'UTF-8');
    $receiver_address = htmlspecialchars(trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ), ENT_XML1, 'UTF-8');
    $city_name        = htmlspecialchars($order->get_shipping_city(), ENT_XML1, 'UTF-8');
    $town_name        = htmlspecialchars($order->get_shipping_state(), ENT_XML1, 'UTF-8');
    $receiver_phone   = htmlspecialchars($order->get_billing_phone(), ENT_XML1, 'UTF-8');
    
    $create_soap_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><soapenv:Header/><soapenv:Body><ship:createShipment><wsUserName>{$ws_user_name}</wsUserName><wsPassword>{$ws_password}</wsPassword><userLanguage>TR</userLanguage><ShippingOrderVO><cargoKey>{$unique_cargo_key}</cargoKey><invoiceKey>{$unique_cargo_key}</invoiceKey><receiverCustName>{$receiver_name}</receiverCustName><receiverAddress>{$receiver_address}</receiverAddress><cityName>{$city_name}</cityName><townName>{$town_name}</townName><receiverPhone1>{$receiver_phone}</receiverPhone1></ShippingOrderVO></ship:createShipment></soapenv:Body></soapenv:Envelope>
XML;

    $response_create = wp_remote_post( $api_url, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8'],'body' => $create_soap_request,'timeout' => 30] );

    if ( is_wp_error( $response_create ) ) {
        $order->add_order_note('❌ Adım 1 Hatası: Gönderi oluşturulurken API\'ye bağlanılamadı: ' . $response_create->get_error_message());
        return;
    }
    
    $response_create_body = wp_remote_retrieve_body($response_create);
    
    try {
        if (empty($response_create_body)) { throw new Exception("API yanıtı boş geldi."); }
        
        // Gelen yanıtta 'faultstring' kelimesi varsa, bu doğrudan bir XML hatasıdır.
        if (strpos($response_create_body, 'faultstring') !== false) {
             // Hatayı daha okunaklı hale getirelim.
            preg_match('/<faultstring>(.*?)<\/faultstring>/', $response_create_body, $matches);
            $error_message = isset($matches[1]) ? $matches[1] : 'Genel XML Hatası';
            throw new Exception($error_message);
        }

        $dom = new DOMDocument();
        $dom->loadXML($response_create_body);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ns1', 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices');
        $out_flag_nodes = $xpath->query('//ns1:createShipmentResponse/ShippingOrderResultVO/outFlag');
        
        if ($out_flag_nodes->length > 0) {
            if ((int) $out_flag_nodes->item(0)->nodeValue !== 0) {
                $error_message_nodes = $xpath->query('//ns1:createShipmentResponse/ShippingOrderResultVO/shippingOrderDetailVO/errMessage');
                $error_message = ($error_message_nodes->length > 0) ? $error_message_nodes->item(0)->nodeValue : "Bilinmeyen API hatası.";
                throw new Exception($error_message);
            }
        } else {
            throw new Exception("Yanıt içinde 'outFlag' değeri bulunamadı. Gelen Ham Yanıt: " . esc_html($response_create_body));
        }

    } catch (Exception $e) {
        $order->add_order_note('❌ Adım 1 Hatası: Gönderi oluşturulamadı. API Yanıtı: ' . $e->getMessage());
        return;
    }
    
    $order->add_order_note('✅ Adım 1 Başarılı: Gönderi kaydedildi. Şimdi resmi takip no alınıyor...');
    
    // ADIM 2: Oluşturulan gönderinin resmi takip numarasını sorgula
       // =================================================================
       $query_soap_request = <<<XML
   <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><soapenv:Header/><soapenv:Body><ship:queryShipment><wsUserName>{$ws_user_name}</wsUserName><wsPassword>{$ws_password}</wsPassword><userLanguage>TR</userLanguage><keys>{$unique_cargo_key}</keys><keyType>0</keyType><addHistoricalData>false</addHistoricalData><onlyTracking>false</onlyTracking></ship:queryShipment></soapenv:Body></soapenv:Envelope>
   XML;

       $response_query = wp_remote_post( $api_url, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8'], 'body' => $query_soap_request, 'timeout' => 30] );

       if ( is_wp_error( $response_query ) ) {
           $order->add_order_note('❌ Adım 2 Hatası: Resmi takip numarası sorgulanırken API\'ye bağlanılamadı.');
           return;
       }

       $response_query_body = wp_remote_retrieve_body($response_query);
       try {
           $dom_q = new DOMDocument();
           $dom_q->loadXML($response_query_body);
           $xpath_q = new DOMXPath($dom_q);
           $xpath_q->registerNamespace('ns1', 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices');
           
           $doc_id_nodes = $xpath_q->query('//ns1:queryShipmentResponse/ShippingDeliveryVO/shippingDeliveryDetailVO/docId');

           if ($doc_id_nodes->length > 0 && !empty($doc_id_nodes->item(0)->nodeValue)) {
               $real_tracking_number = $doc_id_nodes->item(0)->nodeValue;
               if(function_exists('ast_insert_tracking_number')) {
                   ast_insert_tracking_number( $order_id, $real_tracking_number, 'yurtici-kargo', date('Y-m-d') );
               }
               $order->add_order_note( "✅ Adım 2 Başarılı: Resmi Yurtiçi Kargo takip numarası alındı ve kaydedildi: {$real_tracking_number}" );
           } else {
               if(function_exists('ast_insert_tracking_number')) {
                   ast_insert_tracking_number( $order_id, $unique_cargo_key, 'yurtici-kargo', date('Y-m-d') );
               }
               $order->add_order_note( "⚠️ Bilgi: Resmi takip numarası henüz oluşmamış. Referans kodu kaydedildi: {$unique_cargo_key}. Kargo şubede okutulunca link çalışacaktır." );
           }
       } catch (Exception $e) {
           $order->add_order_note('❌ Adım 2 Hatası: Resmi takip numarası sorgulanırken API yanıtı işlenemedi. Gelen Ham Yanıt: ' . esc_html($response_query_body));
       }
   }

// ===================================================================================
// BÖLÜM 2: BARKOD GÖRÜNTÜLEME
// ===================================================================================
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'yk_barkodu_siparis_ekraninda_goster', 10, 1 );
function yk_barkodu_siparis_ekraninda_goster( $order ) {
    if ( !function_exists('ast_get_tracking_items') ) return;
    $tracking_items = ast_get_tracking_items( $order->get_id() );
    if ( !empty($tracking_items) && isset($tracking_items[0]['tracking_provider']) && $tracking_items[0]['tracking_provider'] === 'yurtici-kargo' ) {
        $tracking_number = $tracking_items[0]['tracking_number'];
        $barcode_image_data = yk_dahili_barkod_uret($tracking_number);
        $base64_barcode = base64_encode($barcode_image_data);
        echo '<div style="border: 1px solid #ddd; padding: 15px; margin-top: 20px;"><h3>Yurtiçi Kargo Gönderi Barkodu</h3><div style="padding: 10px; background: white; text-align: center;"><img src="data:image/png;base64,' . $base64_barcode . '" alt="Kargo Barkodu" /><p style="letter-spacing: 2px; font-weight: bold; margin-top: 5px;">' . esc_html($tracking_number) . '</p></div></div>';
    }
}

// ===================================================================================
// BÖLÜM 3: DAHİLİ BARKOD ÜRETME FONKSİYONU (HARİCİ DOSYA GEREKTİRMEZ)
// ===================================================================================
function yk_dahili_barkod_uret($text) {
    $code_map = [' '=>'212222', '!' => '222122', '"' => '222221', '#' => '121223', '$' => '121322', '%' => '131222', '&' => '122213', "'" => '122312', '(' => '132212', ')' => '221213', '*' => '221312', '+' => '231212', ',' => '112232', '-' => '122132', '.' => '122231', '/' => '113222', '0' => '123122', '1' => '122132', '2' => '122231', '3' => '112232', '4' => '113222', '5' => '123221', '6' => '112223', '7' => '112322', '8' => '122321', '9' => '122123', ':' => '123131', ';' => '132131', '<' => '133121', '=' => '121331', '>' => '131231', '?' => '131321', '@' => '121212', 'A' => '121212', 'B' => '121212', 'C' => '121212', 'D' => '121212', 'E' => '121212', 'F' => '121212', 'G' => '121212', 'H' => '121212', 'I' => '121212', 'J' => '121212', 'K' => '121212', 'L' => '121212', 'M' => '121212', 'N' => '121212', 'O' => '121212', 'P' => '121212', 'Q' => '121212', 'R' => '121212', 'S' => '121212', 'T' => '121212', 'U' => '121212', 'V' => '121212', 'W' => '121212', 'X' => '121212', 'Y' => '121212', 'Z' => '121212', '[' => '121212', '\\' => '121212', ']' => '121212', '^' => '121212', '_' => '121212', '`' => '121212', 'a' => '121212', 'b' => '121212', 'c' => '121212', 'd' => '121212', 'e' => '121212', 'f' => '121212', 'g' => '121212', 'h' => '121212', 'i' => '121212', 'j' => '121212', 'k' => '121212', 'l' => '121212', 'm' => '121212', 'n' => '121212', 'o' => '121212', 'p' => '121212', 'q' => '121212', 'r' => '121212', 's' => '121212', 't' => '121212', 'u' => '121212', 'v' => '121212', 'w' => '121212', 'x' => '121212', 'y' => '121212', 'z' => '121212', '{' => '121212', '|' => '121212', '}' => '121212', '~' => '121212', 'Ç' => '121212', 'ü' => '121212', 'é' => '121212', 'â' => '121212', 'ä' => '121212', 'à' => '121212', 'å' => '121212', 'ç' => '121212', 'ê' => '121212', 'ë' => '121212', 'è' => '121212', 'ï' => '121212', 'î' => '121212', 'ì' => '121212', 'Ä' => '121212', 'Å' => '121212', 'É' => '121212', 'æ' => '121212', 'Æ' => '121212', 'ô' => '121212', 'ö' => '121212', 'ò' => '121212', 'û' => '121212', 'ù' => '121212', 'ÿ' => '121212', 'Ö' => '121212', 'Ü' => '121212', 'ø' => '121212', 'Ø' => '121212', '×' => '121212', 'ƒ' => '121212', 'á' => '121212', 'í' => '121212', 'ó' => '121212', 'ú' => '121212', 'ñ' => '121212', 'Ñ' => '121212', 'ª' => '121212', 'º' => '121212', '¿' => '121212', '®' => '121212', '¬' => '121212', '½' => '121212', '¼' => '121212', '¡' => '121212', '«' => '121212', '»' => '121212', '░' => '121212', '▒' => '121212', '▓' => '121212', '│' => '121212', '┤' => '121212', 'Á' => '121212', 'Â' => '121212', 'À' => '121212', '©' => '121212', '╣' => '121212', '║' => '121212', '╗' => '121212', '╝' => '121212', '¢' => '121212', '¥' => '121212', '┐' => '121212', '└' => '121212', '┴' => '121212', '┬' => '121212', '├' => '121212', '─' => '121212', '┼' => '121212', 'ã' => '121212', 'Ã' => '121212', '╚' => '121212', '╔' => '121212', '╩' => '121212', '╦' => '121212', '╠' => '121212', '═' => '121212', '╬' => '121212', '¤' => '121212', 'ð' => '121212', 'Ð' => '121212', 'Ê' => '121212', 'Ë' => '121212', 'È' => '121212', 'ı' => '121212', 'Í' => '121212', 'Î' => '121212', 'Ï' => '121212', '┘' => '121212', '┌' => '121212', '█' => '121212', '▄' => '121212', '¦' => '121212', 'Ì' => '121212', '▀' => '121212', 'Ó' => '121212', 'ß' => '121212', 'Ô' => '121212', 'Ò' => '121212', 'õ' => '121212', 'Õ' => '121212', 'µ' => '121212', 'þ' => '121212', 'Þ' => '121212', 'Ú' => '121212', 'Û' => '121212', 'Ù' => '121212', 'ý' => '121212', 'Ý' => '121212', '¯' => '121212', '´' => '121212', '­' => '121212', '±' => '121212', '‗' => '121212', '¾' => '121212', '¶' => '121212', '§' => '121212', '÷' => '121212', '¸' => '121212', '°' => '121212', '¨' => '121212', '·' => '121212', '¹' => '121212', '³' => '121212', '²' => '121212', '■' => '121212', ' ' => '212222', 'FNC3' => '212222', 'FNC2' => '212222', 'SHIFT' => '212222', 'CODEC' => '212222', 'CODEB' => '212222', 'FNC4' => '212222', 'FNC1' => '212222', 'StartA' => '211412', 'StartB' => '211214', 'StartC' => '211232', 'Stop' => '2331112' ];
    $code_keys = array_keys($code_map); $code_vals = array_values($code_map);
    $width = 2; $height = 60;
    $barcode_array = [];
    $curr_char = 'B';
    for ($i = 0; $i < strlen($text); $i++) { $char = $text[$i]; if (is_numeric(substr($text, $i, 2)) && ($curr_char == 'C')) { $barcode_array[] = $code_vals[array_search(substr($text, $i, 2), $code_keys)]; $i++; } else { if ($curr_char == 'C') { $barcode_array[] = $code_vals[array_search('CODEB', $code_keys)]; $curr_char = 'B'; } $barcode_array[] = $code_vals[array_search($char, $code_keys)]; } }
    $barcode_array_str = implode('', $barcode_array);
    $im = imagecreate($width * strlen($barcode_array_str), $height);
    $black = imagecolorallocate($im, 0, 0, 0); $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $white);
    $x = 0;
    for ($i = 0; $i < strlen($barcode_array_str); $i++) { $val = $barcode_array_str[$i]; $w = $width * $val; if ($i % 2 == 0) { imagefilledrectangle($im, $x, 0, $x + $w - 1, $height, $black); } $x += $w; }
    ob_start();
    imagepng($im);
    $image_data = ob_get_contents();
    ob_end_clean();
    imagedestroy($im);
    return $image_data;
}

// ===================================================================================
// BÖLÜM 4: CRON JOB İLE OTOMATİK DURUM GÜNCELLEME
// ===================================================================================
add_filter( 'cron_schedules', 'yk_cron_saatlik_zaman_araligi_ekle' );
function yk_cron_saatlik_zaman_araligi_ekle( $schedules ) {
    if (!isset($schedules["hourly"])) { $schedules['hourly'] = array('interval' => 3600, 'display' => esc_html__( 'Every Hour' )); }
    return $schedules;
}
register_activation_hook( __FILE__, 'yk_cron_aktivasyonu' );
function yk_cron_aktivasyonu() {
    if ( ! wp_next_scheduled( 'yk_saatlik_kargo_guncelleme_event' ) ) { wp_schedule_event( time(), 'hourly', 'yk_saatlik_kargo_guncelleme_event' ); }
}
add_action( 'yk_saatlik_kargo_guncelleme_event', 'yk_kargo_durumlarini_guncelle' );
function yk_kargo_durumlarini_guncelle() {
  $api_url = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices?wsdl';
    $ws_user_name = 'YKTEST'; $ws_password = 'YK'; 
    if (!function_exists('wc_get_orders')) return;
    $orders_to_check = wc_get_orders( array('status' => 'kargoya-hazir', 'limit'  => -1) );
    if ( empty( $orders_to_check ) ) { return; }
    $tracking_keys = [];
    $order_map = [];
    foreach ( $orders_to_check as $order ) {
        if ( function_exists('ast_get_tracking_items') ) {
            $tracking_items = ast_get_tracking_items( $order->get_id() );
            if ( ! empty( $tracking_items ) ) {
                $tracking_number = $tracking_items[0]['tracking_number'];
                $tracking_keys[] = "<keys>{$tracking_number}</keys>";
                $order_map[$tracking_number] = $order->get_id();
            }
        }
    }
    if ( empty( $tracking_keys ) ) { return; }
    $keys_xml_string = implode('', $tracking_keys);
    $soap_request_body = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><soapenv:Header/><soapenv:Body><ship:queryShipment><wsUserName>{$ws_user_name}</wsUserName><wsPassword>{$ws_password}</wsPassword><userLanguage>TR</userLanguage>{$keys_xml_string}<keyType>0</keyType><addHistoricalData>false</addHistoricalData><onlyTracking>false</onlyTracking></ship:queryShipment></soapenv:Body></soapenv:Envelope>
XML;
    $response = wp_remote_post( $api_url, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8'], 'body' => $soap_request_body, 'timeout' => 60]);
    if ( is_wp_error( $response ) || empty(wp_remote_retrieve_body( $response )) ) { return; }
    $response_body = wp_remote_retrieve_body( $response );
    try {
        $xml_object = new SimpleXMLElement( preg_replace( '/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response_body ) );
        $results = $xml_object->soapBody->queryShipmentResponse->ShippingDeliveryVO;
        if (isset($results->shippingDeliveryDetailVO)) {
            foreach($results->shippingDeliveryDetailVO as $delivery_detail) {
                $tracking_number = (string) $delivery_detail->cargoKey;
                $status_code = (string) $delivery_detail->operationStatus;
                if ( $status_code === 'DLV' && isset($order_map[$tracking_number]) ) {
                    $order_id_to_update = $order_map[$tracking_number];
                    $order = wc_get_order($order_id_to_update);
                    if ($order && $order->get_status() !== 'completed') {
                        $order->update_status('completed', 'Kargo durumu API üzerinden "Teslim Edildi" olarak güncellendi.');
                    }
                }
            }
        }
    } catch (Exception $e) { /* XML parse hatası */ }
}
register_deactivation_hook( __FILE__, 'yk_cron_iptali' );
function yk_cron_iptali() {
    $timestamp = wp_next_scheduled( 'yk_saatlik_kargo_guncelleme_event' );
    wp_unschedule_event( $timestamp, 'yk_saatlik_kargo_guncelleme_event' );
}
// ===================================================================================
// BÖLÜM 5: GÖNDERİ İPTAL ETME ÖZELLİĞİ
// ===================================================================================

/**
 * Sipariş detay sayfasına "Kargo İptal" meta kutusunu ekler.
 */
add_action( 'add_meta_boxes', 'yk_iptal_meta_box_ekle' );
function yk_iptal_meta_box_ekle() {
    // Sadece takip numarası olan siparişlerde bu kutuyu göster
    global $post;
    if ( function_exists('ast_get_tracking_items') && ast_get_tracking_items($post->ID) ) {
        add_meta_box(
            'yk_kargo_iptal_box',
            'Yurtiçi Kargo İşlemleri',
            'yk_iptal_meta_box_icerigi',
            'shop_order',
            'side',
            'core'
        );
    }
}

/**
 * Meta kutusunun içeriğini (iptal butonunu) oluşturur.
 */
function yk_iptal_meta_box_icerigi( $post ) {
    $order_id = $post->ID;
    
    // Güvenlik için bir nonce oluşturuyoruz.
    $iptal_linki = wp_nonce_url(
        admin_url( 'post.php?post=' . $order_id . '&action=edit&yk_kargo_iptal_et=true' ),
        'yk_kargo_iptal_nonce_' . $order_id
    );

    echo '<p>Oluşturulan kargo gönderisini iptal etmek için aşağıdaki butonu kullanabilirsiniz.</p>';
    echo '<p><strong>Not:</strong> Sadece şubeden çıkış yapmamış kargolar iptal edilebilir.</p>';
    echo '<a href="' . esc_url($iptal_linki) . '" class="button button-primary" style="width: 100%; text-align: center; background-color: #d9534f; border-color: #d43f3a;" onclick="return confirm(\'Bu kargo gönderisini iptal etmek istediğinizden emin misiniz?\');">Kargoyu İptal Et</a>';
}

/**
 * İptal butonuna basıldığında çalışan ana fonksiyon.
 */
add_action( 'admin_init', 'yk_kargo_iptal_istegini_isle' );
function yk_kargo_iptal_istegini_isle() {
    // Sadece doğru parametreler varsa ve yetki kontrolü başarılıysa çalış.
    if ( !isset($_GET['yk_kargo_iptal_et']) || !isset($_GET['post']) ) {
        return;
    }
    
    $order_id = intval($_GET['post']);
    
    // Nonce güvenlik kontrolü
    check_admin_referer( 'yk_kargo_iptal_nonce_' . $order_id );

    $order = wc_get_order($order_id);
    if ( !$order ) return;
    
    $tracking_items = function_exists('ast_get_tracking_items') ? ast_get_tracking_items($order_id) : [];

    if ( empty($tracking_items) ) {
        $order->add_order_note('İptal Hatası: Siparişe ait bir takip numarası bulunamadı.');
        return;
    }

    $tracking_number = $tracking_items[0]['tracking_number'];
    $tracking_id = $tracking_items[0]['tracking_id'];

    // API Bilgileri
  $api_url = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices?wsdl';
    $ws_user_name = 'YKTEST'; $ws_password = 'YK'; 

    $soap_request_body = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices">
   <soapenv:Header/>
   <soapenv:Body>
      <ship:cancelShipment>
         <wsUserName>{$ws_user_name}</wsUserName>
         <wsPassword>{$ws_password}</wsPassword>
         <userLanguage>TR</userLanguage>
         <cargoKeys>{$tracking_number}</cargoKeys>
      </ship:cancelShipment>
   </soapenv:Body>
</soapenv:Envelope>
XML;

    $response = wp_remote_post( $api_url, ['body' => $soap_request_body, 'headers' => ['Content-Type' => 'text/xml; charset=utf-8'], 'timeout' => 30] );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note('❌ Kargo iptal edilemedi. API bağlantı hatası: ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        try {
            $xml = new SimpleXMLElement(preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response_body));
            $result = $xml->soapBody->cancelShipmentResponse->ShippingOrderResultVO;
            $out_flag = (int) $result->outFlag;

            if ($out_flag === 0) {
                // Başarılı!
                $order->add_order_note('✅ Yurtiçi Kargo gönderisi başarıyla iptal edildi.');
                // AST'den takip numarasını sil
                if (function_exists('ast_delete_tracking_item')) {
                    ast_delete_tracking_item($order_id, $tracking_id);
                }
            } else {
                // Başarısız
                $err_message = (string) $result->shippingCancelDetailVO->errMessage;
                $order->add_order_note('❌ Kargo iptal edilemedi. API Yanıtı: ' . $err_message);
            }
        } catch (Exception $e) {
            $order->add_order_note('❌ Kargo iptali sırasında API yanıtı işlenemedi.');
        }
    }
    
    // Sayfanın yeniden gönderilmesini önlemek için kullanıcıyı temiz bir URL'ye yönlendir.
    wp_safe_redirect( admin_url('post.php?post=' . $order_id . '&action=edit') );
    exit();
}
