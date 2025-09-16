

# WooCommerce için Yurtiçi Kargo Otomatik Gönderi Entegrasyonu

Bu WordPress eklentisi, WooCommerce mağazanız ile Yurtiçi Kargo arasında tam otomasyon sağlar. Herhangi bir yıllık/aylık ücret ödemeden, kendi sunucunuzda barındıracağınız bu eklenti ile aşağıdaki işlemleri otomatik olarak yapabilirsiniz:

-   Belirlediğiniz sipariş durumunda (örn: "Kargoya Hazır") Yurtiçi Kargo API'sine **otomatik olarak gönderi oluşturma**.
-   API'den dönen takip numarasını siparişe ve "Advanced Shipment Tracking" eklentisine **otomatik olarak kaydetme**.
-   Müşteriye takip linki içeren e-postanın **otomatik olarak gönderilmesini** sağlama.
-   Sipariş detay sayfasında pakete yapıştırılacak **gönderi barkodunu gösterme**.
-   Saatlik olarak kargo durumlarını kontrol edip "Teslim Edildi" olan siparişleri **otomatik "Tamamlandı" yapma**.

Bu proje, özellikle PHP ve WordPress konusunda deneyimli olmayan ancak kendi entegrasyonuna sahip olmak isteyen geliştiriciler ve mağaza sahipleri için bir rehber niteliğindedir.

## Gereksinimler

Bu eklentinin sorunsuz çalışabilmesi için sitenizde aşağıdaki eklentilerin **yüklü ve aktif** olması gerekmektedir:

1.  **WooCommerce:** Ana e-ticaret eklentisi.
2.  **Advanced Shipment Tracking (AST) - Free:** Takip numaralarını yönetmek ve müşteriye göstermek için kullandığımız temel eklenti. (Ücretsiz sürümü yeterlidir).
3.  **Custom Order Status for WooCommerce:** "Kargoya Hazır" gibi özel bir sipariş durumu oluşturarak süreci daha kontrollü hale getirmek için **şiddetle tavsiye edilir**.

## Kurulum Adımları

Bu entegrasyonu sitenize kurmak için aşağıdaki 4 adımı sırasıyla takip ediniz.

### Adım 1: Gerekli Eklentileri Yükleyin

WordPress Admin Panelinize gidin ve `Eklentiler > Yeni Ekle` menüsünden aşağıdaki eklentileri aratıp kurun ve etkinleştirin:

-   `Advanced Shipment Tracking for WooCommerce`
-   `Custom Order Status for WooCommerce`

### Adım 2: Özel Sipariş Durumunu Oluşturun

Kargo gönderimini ne zaman tetikleyeceğinizi kontrol etmek için özel bir sipariş durumu oluşturmak en iyi yöntemdir.

1.  WordPress Admin Panelinde `WooCommerce > Sipariş Durumları` menüsüne gidin.
2.  "Yeni Ekle" butonuna tıklayın.
3.  Aşağıdaki bilgileri girin:
    -   **İsim:** `Kargoya Hazır`
    -   **Slug (kısa ad):** `kargoya-hazir` (**Önemli:** Kodumuz bu slug'ı kullanır, bu yüzden tam olarak böyle yazdığınızdan emin olun).
4.  Kaydedin.

### Adım 3: Bu Eklentiyi Sitenize Yükleyin

1.  Bu GitHub reposunun ana sayfasına gidin.
2.  Yeşil **`< > Code`** butonuna tıklayın ve **"Download ZIP"** seçeneği ile projenin tamamını bilgisayarınıza indirin.
3.  İndirdiğiniz `yk-entegrasyonu-main.zip` dosyasını açın.
4.  İçindeki `benim-yk-entegrasyonum` klasörünü bulun ve bu klasörü **yeniden ZIP'leyin**. (Yani `benim-yk-entegrasyonum.zip` adında bir dosyanız olmalı).
5.  WordPress Admin Panelinde `Eklentiler > Yeni Ekle > Eklenti Yükle` menüsüne gidin.
6.  Oluşturduğunuz `benim-yk-entegrasyonum.zip` dosyasını seçip yükleyin ve eklentiyi etkinleştirin.

### Adım 4: API Bilgilerinizi Eklentiye Girin

Eklentinin Yurtiçi Kargo ile konuşabilmesi için size özel API bilgilerini girmeniz gerekmektedir.

1.  WordPress Admin Panelinde `Eklentiler > Eklenti Düzenleyici` menüsüne gidin.
2.  Sağ üstteki "Düzenlenecek eklentiyi seçin" menüsünden **"Benim Yurtiçi Kargo Entegrasyonum"**u seçin ve "Seç" butonuna tıklayın.
3.  Kod dosyasının içinde `BÖLÜM 1` ve `BÖLÜM 3`'te bulunan `--- API AYARLARI ---` bölümlerini bulun.
4.  Test yapmak için varsayılan olarak bırakılmış `YKTEST` bilgilerini, Yurtiçi Kargo'dan aldığınız **canlı kullanıcı adı ve şifrenizle** değiştirin.
5.  "Dosyayı Güncelle" butonuna tıklayarak değişiklikleri kaydedin.

**Tebrikler! Kurulum tamamlandı.**

## Nasıl Kullanılır?

1.  WooCommerce'den yeni bir sipariş geldiğinde, ürünü paketleyip hazırlayın.
2.  Hazır olduğunuzda, siparişin detay sayfasına gidin.
3.  Sipariş durumunu **"Kargoya Hazır"** olarak değiştirin ve "Güncelle" butonuna tıklayın.
4.  Sayfa yenilendiğinde:
    -   Sağdaki "Sipariş Notları" kutucuğunda "✅ Yurtiçi Kargo gönderisi başarıyla oluşturuldu..." mesajını göreceksiniz.
    -   Kargo adresi kutucuğunun altında, üzerinde takip numarasının yazdığı bir **barkod** belirecektir.
5.  Bu barkodu yazdırıp paketin üzerine yapıştırabilir veya doğrudan kargo görevlisine okutabilirsiniz.

Sistem, geri kalan her şeyi (müşteriye bildirim, kargo durumu takibi vb.) otomatik olarak halledecektir.

## Katkıda Bulunma

Bu proje topluluğun kullanımı için geliştirilmiştir. İyileştirme önerileriniz veya bulduğunuz hatalar için lütfen bir "Issue" açın veya "Pull Request" gönderin.
