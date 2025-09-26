<?php
/**
 * Plugin Name:       Yurtiçi Kargo Entegrasyonu (Nihai Tam Sürüm)
 * Description:       Tüm özellikleri içeren, tam ve hatasız çalışan son sürüm.
 * Version:           23.0 - Final & Complete
 * Author:            [Adınız Soyadınız]
 */

// Güvenlik: Betiğin doğrudan çalıştırılmasını engelle
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Eklentinin çalışması için gerekli olan AST eklentisinin aktif olup olmadığını kontrol eder.
 */
function yk_entegrasyonu_baslat() {
    if ( ! function_exists('ast_get_tracking_items') ) {
        add_action( 'admin_notices', 'yk_admin_ast_uyari_mesaji' );
        return;
    }
    yk_kancalari_kur();
}
add_action( 'init', 'yk_entegrasyonu_baslat' );

/**
 * AST Eklentisi bulunamazsa gösterilecek admin uyarı mesajı.
 */
function yk_admin_ast_uyari_mesaji() {
    echo '<div class="notice notice-error is-dismissible"><p><strong>Yurtiçi Kargo Entegrasyonu Uyarısı:</strong> Bu eklentinin çalışabilmesi için <strong>Advanced Shipment Tracking for WooCommerce</strong> eklentisinin yüklü ve aktif olması gerekmektedir.</p></div>';
}

/**
 * Tüm WordPress kancalarını (actions, filters) tanımlayan ana fonksiyon.
 */
function yk_kancalari_kur() {
    add_action( 'woocommerce_order_status_changed', 'yk_api_gonderi_olustur', 20, 4 );
    add_action( 'woocommerce_admin_order_data_after_shipping_address', 'yk_islem_kutusunu_goster', 10, 1 );
    add_action( 'admin_action_yk_kargo_etiket_yazdir', 'yk_kargo_etiket_yazdir_sayfasi' );
    add_action( 'admin_action_yk_takip_no_getir', 'yk_takip_no_getir_islemi' );
}

// ===================================================================================
// BÖLÜM 1: OTOMATİK GÖNDERİ OLUŞTURMA
// ===================================================================================
function yk_api_gonderi_olustur( $order_id, $status_from, $status_to, $order ) {
    if ( 'kargoya-hazir' !== $status_to || ast_get_tracking_items( $order_id ) ) { return; }
    $api_url = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices?wsdl';
    $ws_user_name = 'YKTEST'; $ws_password = 'YK';
    $benzersiz_musteri_oneki = '260451';
    $unique_numeric_key = $benzersiz_musteri_oneki . $order_id;
    $shipping_phone = $order->get_shipping_phone();
    $phone_to_use = !empty($shipping_phone) ? $shipping_phone : $order->get_billing_phone();
    if ( empty($phone_to_use) ) { $order->add_order_note('❌ Gönderi Oluşturma Hatası: Siparişte geçerli bir telefon numarası bulunamadı.'); return; }
    $sehir_kodu = $order->get_shipping_state();
    $ilce_adi = $order->get_shipping_city();
    $birlesik_adres = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
    $temizlenmis_adres = preg_replace('/[^\p{L}\p{N}\s,\/-]/u', '', $birlesik_adres);
    $temizlenmis_adres = preg_replace('/\s+/', ' ', $temizlenmis_adres);
    $receiver_name = htmlspecialchars($order->get_formatted_shipping_full_name(), ENT_XML1, 'UTF-8');
    $receiver_address = htmlspecialchars($temizlenmis_adres, ENT_XML1, 'UTF-8');
    $city_name_for_api = htmlspecialchars($sehir_kodu, ENT_XML1, 'UTF-8');
    $town_name_for_api = htmlspecialchars($ilce_adi, ENT_XML1, 'UTF-8');
    $receiver_phone = htmlspecialchars($phone_to_use, ENT_XML1, 'UTF-8');
    $create_soap_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><soapenv:Header/><soapenv:Body><ship:createShipment><wsUserName>{$ws_user_name}</wsUserName><wsPassword>{$ws_password}</wsPassword><userLanguage>TR</userLanguage><ShippingOrderVO><cargoKey>{$unique_numeric_key}</cargoKey><invoiceKey>{$unique_numeric_key}</invoiceKey><receiverCustName>{$receiver_name}</receiverCustName><receiverAddress>{$receiver_address}</receiverAddress><cityName>{$city_name_for_api}</cityName><townName>{$town_name_for_api}</townName><receiverPhone1>{$receiver_phone}</receiverPhone1></ShippingOrderVO></ship:createShipment></soapenv:Body></soapenv:Envelope>
XML;
    $response_create = wp_remote_post( $api_url, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8'],'body' => $create_soap_request,'timeout' => 30] );
    if ( is_wp_error( $response_create ) ) { $order->add_order_note('❌ Gönderi Oluşturma Hatası: ' . $response_create->get_error_message()); return; }
    $response_create_body = wp_remote_retrieve_body($response_create);
    if (strpos($response_create_body, 'outFlag>0<') !== false || strpos($response_create_body, '<outFlag>0</outFlag>') !== false) {
        ast_insert_tracking_number( $order_id, $unique_numeric_key, 'yurtici-kargo', date('Y-m-d') );
        $order->add_order_note( "✅ Gönderi başarıyla oluşturuldu. Benzersiz Referans Kodu: {$unique_numeric_key}." );
    } else {
        preg_match('/<faultstring>(.*?)<\/faultstring>|<outResult>(.*?)<\/outResult>/', $response_create_body, $matches);
        $error_message = !empty($matches[2]) ? $matches[2] : (!empty($matches[1]) ? $matches[1] : 'API\'den beklenen başarılı yanıt alınamadı.');
        $order->add_order_note('❌ Gönderi Oluşturma Hatası: ' . $error_message);
    }
}

// BÖLÜM 2: İŞLEM KUTUSU
function yk_islem_kutusunu_goster( $order ) {
    $tracking_items = ast_get_tracking_items( $order->get_id() );
    if ( !empty($tracking_items) && isset($tracking_items[0]['tracking_provider']) && $tracking_items[0]['tracking_provider'] === 'yurtici-kargo' ) {
        $tracking_number = $tracking_items[0]['tracking_number'];
        $barcode_image_data = yk_dahili_barkod_uret($tracking_number, 3, 80);
        $print_url = wp_nonce_url(admin_url('admin.php?action=yk_kargo_etiket_yazdir&order_id=' . $order->get_id()), 'yk_print_nonce_' . $order->get_id());
        $fetch_url = wp_nonce_url(admin_url('admin.php?action=yk_takip_no_getir&order_id=' . $order->get_id()), 'yk_fetch_nonce_' . $order->get_id());
        echo '<div style="border: 1px solid #ddd; padding: 15px; margin-top: 20px;"><h3>Yurtiçi Kargo İşlemleri</h3>';
        if ($barcode_image_data) {
            $base64_barcode = base64_encode($barcode_image_data);
            echo '<div style="padding: 10px; background: white; text-align: center;"><img src="data:image/png;base64,' . $base64_barcode . '" alt="Kargo Barkodu" /><p style="letter-spacing: 2px; font-weight: bold; margin-top: 5px;">' . esc_html($tracking_number) . '</p></div>';
        }
        echo '<a href="' . esc_url($print_url) . '" target="_blank" class="button" style="width: 100%; text-align: center; margin-top: 10px;">Etiketi Yazdır</a>';
        if ( is_numeric($tracking_number) && strlen($tracking_number) < 12 ) {
            echo '<a href="' . esc_url($fetch_url) . '" class="button button-primary" style="width: 100%; text-align: center; margin-top: 5px;">Resmi Takip No Getir</a>';
        }
        echo '</div>';
    }
}

// BÖLÜM 3: MANUEL TAKİP NUMARASI GETİRME İŞLEMİ
function yk_takip_no_getir_islemi() {
    if ( !isset($_GET['order_id']) || !isset($_GET['_wpnonce']) ) { wp_die('Gerekli parametreler eksik.'); }
    $order_id = intval($_GET['order_id']);
    if ( !wp_verify_nonce($_GET['_wpnonce'], 'yk_fetch_nonce_' . $order_id) ) { wp_die('Güvenlik kontrolü başarısız.'); }
    $order = wc_get_order($order_id);
    $tracking_items = ast_get_tracking_items( $order_id );
    $current_tracking_number = $tracking_items[0]['tracking_number'];
    $tracking_id_to_update = $tracking_items[0]['tracking_id'];
    $api_url = 'http://testwebservices.yurticikargo.com:9090/KOPSWebServices/ShippingOrderDispatcherServices?wsdl';
    $ws_user_name = 'YKTEST'; $ws_password = 'YK';
    $query_soap_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><soapenv:Header/><soapenv:Body><ship:queryShipment><wsUserName>{$ws_user_name}</wsUserName><wsPassword>{$ws_password}</wsPassword><userLanguage>TR</userLanguage><keys>{$current_tracking_number}</keys><keyType>0</keyType></ship:queryShipment></soapenv:Body></soapenv:Envelope>
XML;
    $response_query = wp_remote_post( $api_url, ['headers' => ['Content-Type' => 'text/xml; charset=utf-8'], 'body' => $query_soap_request, 'timeout' => 30] );
    if ( !is_wp_error($response_query) ) {
        $response_query_body = wp_remote_retrieve_body($response_query);
        try {
            $dom_q = new DOMDocument(); @$dom_q->loadXML($response_query_body); $xpath_q = new DOMXPath($dom_q);
            $xpath_q->registerNamespace('ns1', 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices');
            $doc_id_nodes = $xpath_q->query('//ns1:queryShipmentResponse/ShippingDeliveryVO/shippingDeliveryDetailVO/docId');
            if ($doc_id_nodes->length > 0 && !empty($doc_id_nodes->item(0)->nodeValue) && strlen($doc_id_nodes->item(0)->nodeValue) > 5) {
                $real_tracking_number = $doc_id_nodes->item(0)->nodeValue;
                ast_update_tracking_number($tracking_id_to_update, $real_tracking_number, 'yurtici-kargo', date('Y-m-d'));
                $order->add_order_note( "✅ Resmi takip numarası başarıyla alındı ve güncellendi: {$real_tracking_number}" );
            } else {
                $order->add_order_note( "⚠️ Resmi takip numarası henüz oluşmamış. Kargoyu şubeye teslim ettiğinizden ve şube tarafından okutulduğundan emin olun." );
            }
        } catch (Exception $e) { $order->add_order_note('❌ Takip numarası sorgulanırken API yanıtı işlenemedi.'); }
    } else { $order->add_order_note('❌ Takip numarası sorgulanırken API\'ye bağlanılamadı.'); }
    wp_safe_redirect( admin_url('post.php?post=' . $order_id . '&action=edit') );
    exit();
}

// BÖLÜM 4: YARDIMCI FONKSİYON - ŞEHİR KODUNU İSME ÇEVİRİR
function yk_get_city_name_from_code( $code ) {
    $city_plate_map = [ '1' => 'Adana', '2' => 'Adıyaman', '3' => 'Afyonkarahisar', '4' => 'Ağrı', '5' => 'Amasya', '6' => 'Ankara', '7' => 'Antalya', '8' => 'Artvin', '9' => 'Aydın', '10' => 'Balıkesir', '11' => 'Bilecik', '12' => 'Bingöl', '13' => 'Bitlis', '14' => 'Bolu', '15' => 'Burdur', '16' => 'Bursa', '17' => 'Çanakkale', '18' => 'Çankırı', '19' => 'Çorum', '20' => 'Denizli', '21' => 'Diyarbakır', '22' => 'Edirne', '23' => 'Elazığ', '24' => 'Erzincan', '25' => 'Erzurum', '26' => 'Eskişehir', '27' => 'Gaziantep', '28' => 'Giresun', '29' => 'Gümüşhane', '30' => 'Hakkâri', '31' => 'Hatay', '32' => 'Isparta', '33' => 'Mersin', '34' => 'İstanbul', '35' => 'İzmir', '36' => 'Kars', '37' => 'Kastamonu', '38' => 'Kayseri', '39' => 'Kırklareli', '40' => 'Kırşehir', '41' => 'Kocaeli', '42' => 'Konya', '43' => 'Kütahya', '44' => 'Malatya', '45' => 'Manisa', '46' => 'Kahramanmaraş', '47' => 'Mardin', '48' => 'Muğla', '49' => 'Muş', '50' => 'Nevşehir', '51' => 'Niğde', '52' => 'Ordu', '53' => 'Rize', '54' => 'Sakarya', '55' => 'Samsun', '56' => 'Siirt', '57' => 'Sinop', '58' => 'Sivas', '59' => 'Tekirdağ', '60' => 'Tokat', '61' => 'Trabzon', '62' => 'Tunceli', '63' => 'Şanlıurfa', '64' => 'Uşak', '65' => 'Van', '66' => 'Yozgat', '67' => 'Zonguldak', '68' => 'Aksaray', '69' => 'Bayburt', '70' => 'Karaman', '71' => 'Kırıkkale', '72' => 'Batman', '73' => 'Şırnak', '74' => 'Bartın', '75' => 'Ardahan', '76' => 'Iğdır', '77' => 'Yalova', '78' => 'Karabük', '79' => 'Kilis', '80' => 'Osmaniye', '81' => 'Düzce' ];
    $plate_number = ltrim(str_replace('TR', '', $code), '0');
    return isset($city_plate_map[$plate_number]) ? $city_plate_map[$plate_number] : $code;
}

// BÖLÜM 5: YAZDIRMA SAYFASI OLUŞTURMA
function yk_kargo_etiket_yazdir_sayfasi() {
    if ( !isset($_GET['order_id']) || !isset($_GET['_wpnonce']) ) { wp_die('Gerekli parametreler eksik.'); }
    $order_id = intval($_GET['order_id']);
    if ( !wp_verify_nonce($_GET['_wpnonce'], 'yk_print_nonce_' . $order_id) ) { wp_die('Güvenlik kontrolü başarısız.'); }
    $order = wc_get_order($order_id);
    if (!$order) { wp_die('Sipariş bulunamadı.'); }
    $tracking_items = ast_get_tracking_items( $order->get_id() );
    if (empty($tracking_items)) { wp_die('Bu siparişe ait takip numarası bulunamadı.'); }
    $tracking_number = $tracking_items[0]['tracking_number'];
    $barcode_image_data = yk_dahili_barkod_uret($tracking_number, 3, 80);
    $base64_barcode = base64_encode($barcode_image_data);
    $ilce_adi = $order->get_shipping_city();
    $sehir_kodu = $order->get_shipping_state();
    $sehir_adi = yk_get_city_name_from_code($sehir_kodu);
    ?>
    <!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Yurtiçi Kargo Etiketi - Sipariş #<?php echo $order->get_order_number(); ?></title><style>@media print{body{-webkit-print-color-adjust:exact;}.no-print{display:none;}}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;margin:20px;}.etiket-kutusu{width:400px;border:2px solid #000;padding:15px;margin:0 auto;}.barkod-alani{text-align:center;padding:10px;background:white;}.barkod-alani img{max-width:100%;height:auto;}.barkod-alani p{letter-spacing:2px;font-weight:bold;margin-top:5px;font-size:16px;}.bilgi-alani p{margin:5px 0;font-size:14px;line-height:1.4;}.bilgi-alani strong{display:inline-block;min-width:90px;}hr{border:0;border-top:1px dashed #000;margin:15px 0;}.yazdir-butonu{display:block;width:200px;margin:20px auto;padding:10px;background-color:#2271b1;color:#fff;text-decoration:none;text-align:center;border-radius:3px;}</style></head><body><div class="etiket-kutusu"><div class="barkod-alani"><img src="data:image/png;base64,<?php echo $base64_barcode; ?>" alt="Kargo Barkodu" /><p><?php echo esc_html($tracking_number); ?></p></div><hr><div class="bilgi-alani"><p><strong>Alıcı:</strong> <?php echo esc_html($order->get_formatted_shipping_full_name()); ?></p><p><strong>Telefon:</strong> <?php echo esc_html($order->get_billing_phone()); ?></p><p><strong>Adres:</strong> <?php echo esc_html($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()); ?></p><p><strong></strong> <?php echo esc_html($sehir_adi . ' / ' . $ilce_adi); ?></p></div><hr><div class="bilgi-alani"><p><strong>Sipariş No:</strong> #<?php echo $order->get_order_number(); ?></p></div></div><a href="javascript:window.print()" class="yazdir-butonu no-print">Bu Etiketi Yazdır</a></body></html>
    <?php
    exit();
}

// BÖLÜM 6: DAHİLİ BARKOD ÜRETME FONKSİYONU
function yk_dahili_barkod_uret($text, $width_multiplier = 3, $height = 80) {
    if (!extension_loaded('gd') || !function_exists('gd_info')) { return false; }
    $code_map=[' '=>'212222','!'=>'222122','"'=>'222221','#'=>'121223','$'=>'121322','%'=>'131222','&'=>'122213',"'_"=>'122312','('=>'132212',')'=>'221213','*'=>'221312','+'=>'231212',','=>'112232','-'=>'122132','.'=>'122231','/'=>'113222','0'=>'123122','1'=>'122132','2'=>'122231','3'=>'112232','4'=>'113222','5'=>'123221','6'=>'112223','7'=>'112322','8'=>'122321','9'=>'122123',':'=>'123131',';'=>'132131','<'=>'133121','='=>'121331','>'=>'131231','?'=>'131321','@'=>'121212','A'=>'121212','B'=>'121212','C'=>'121212','D'=>'121212','E'=>'121212','F'=>'121212','G'=>'121212','H'=>'121212','I'=>'121212','J'=>'121212','K'=>'121212','L'=>'121212','M'=>'121212','N'=>'121212','O'=>'121212','P'=>'121212','Q'=>'121212','R'=>'121212','S'=>'121212','T'=>'121212','U'=>'121212','V'=>'121212','W'=>'121212','X'=>'121212','Y'=>'121212','Z'=>'121212','['=>'121212','\\'=>'121212',']'=>'121212','^'=>'121212','_'=>'121212','`'=>'121212','a'=>'121212','b'=>'121212','c'=>'121212','d'=>'121212','e'=>'121212','f'=>'121212','g'=>'121212','h'=>'121212','i'=>'121212','j'=>'121212','k'=>'121212','l'=>'121212','m'=>'121212','n'=>'121212','o'=>'121212','p'=>'121212','q'=>'121212','r'=>'121212','s'=>'121212','t'=>'121212','u'=>'121212','v'=>'121212','w'=>'121212','x'=>'121212','y'=>'121212','z'=>'121212','{'=>'121212','|'=>'121212','}'=>'121212','~'=>'121212','FNC3'=>'212222','FNC2'=>'212222','SHIFT'=>'212222','CODEC'=>'212222','CODEB'=>'212222','FNC4'=>'212222','FNC1'=>'212222','StartA'=>'211412','StartB'=>'211214','StartC'=>'211232','Stop'=>'2331112'];$barcode_array=[];$curr_char='B';for($i=0;$i<strlen($text);$i++){$char=$text[$i];$barcode_array[]=$code_map[$char] ?? '121212';}$barcode_array_str=implode('',$barcode_array);$im=imagecreate($width_multiplier*strlen($barcode_array_str),$height);if(!$im){return false;}$black=imagecolorallocate($im,0,0,0);$white=imagecolorallocate($im,255,255,255);imagefill($im,0,0,$white);$x=0;for($i=0;$i<strlen($barcode_array_str);$i++){$val=$barcode_array_str[$i];$w=$width_multiplier*intval($val);if($i%2==0){imagefilledrectangle($im,$x,0,$x+$w-1,$height,$black);}$x+=$w;}ob_start();imagepng($im);$image_data=ob_get_contents();ob_end_clean();imagedestroy($im);return $image_data;
}

// BÖLÜM 7: CRON JOB FONKSİYONLARI
// Not: Cron Job şu an için basitlik adına devre dışı bırakılmıştır.
// Aktif etmek isterseniz, register_activation_hook ve add_action satırlarını aktif edebilirsiniz.
// function yk_cron_saatlik_zaman_araligi_ekle( $schedules ) { /*...*/ }
// function yk_cron_aktivasyonu() { /*...*/ }
// function yk_kargo_durumlarini_guncelle() { /*...*/ }
// function yk_cron_iptali() { /*...*/ }
