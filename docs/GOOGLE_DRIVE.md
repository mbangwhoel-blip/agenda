# Arsip lampiran Google Drive

Role `arsiparis` dapat menambah, mengubah, melihat, dan mengganti lampiran surat. Role ini tidak dapat mengakses agenda kegiatan, Zoom, disposisi, laporan, maupun pengaturan pengguna.

## Konfigurasi

1. Buat service account pada Google Cloud, aktifkan **Google Drive API**, lalu unduh file JSON kredensialnya ke lokasi aman di server yang tidak dapat diakses dari web.
2. Buat folder Google Drive khusus arsip surat dan bagikan folder itu kepada alamat email service account sebagai **Editor**.
3. Isi `.env`:

```dotenv
GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_FOLDER_ID=ID_FOLDER_GOOGLE_DRIVE
GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON_PATH=C:\path-aman\google-service-account.json
```

Saat aktif, setiap lampiran surat baru diunggah ke folder tersebut. Referensi file disimpan di database sebagai `gdrive:<file-id>`. File tidak dibuka untuk publik; preview dan unduh tetap melewati pemeriksaan akses aplikasi.

Jika `GOOGLE_DRIVE_ENABLED=false`, perilaku lama tetap digunakan: lampiran disimpan di folder lokal `uploads/`.
