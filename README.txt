PcConnect
=========

Isi paket:
- public/index.php: aplikasi web utama.
- app/lib/helpers.php: koneksi database, auth, layout, dan analitik.
- tools/PcNalisa-Agent.ps1: agent analisa PC Windows.
- tools/PcNalisa-Run.cmd: launcher agent.
- tools/CARA-PAKAI-PCNALISA.txt: panduan menjalankan agent.
- config/config.php: konfigurasi database, base URL, dan token agent.
- database/schema.sql: struktur database awal.
- database/upgrade_v2.sql: migrasi untuk database PcConnect lama.
- uploads/maintenance: dibuat otomatis saat teknisi upload foto/signature.

Instalasi singkat:
1. Upload folder PcConnect ke web server PHP.
2. Buat database MySQL/MariaDB, lalu import database/schema.sql.
3. Edit config/config.php:
   - db_host, db_name, db_user, db_pass
   - db_port jika MySQL/MariaDB memakai port selain 3306
   - base_url, contoh: http://server/PcConnect/public/index.php
   - mobile_base_url, contoh query bawaan:
     http://server/PcConnect/public/index.php?route=mobile_scan&code=
     atau jika server memakai rewrite:
     https://asset.company.id/m/
   - company_name untuk label QR
   - agent_token, ganti dengan string rahasia yang panjang.
4. Buka base_url di browser.
   Jika masih muncul error database, buka:
   http://server/PcConnect/public/db-check.php
   untuk mengecek host/port database yang aktif.
5. Login awal:
   - username: admin
   - password: admin12345
6. Segera ganti password admin lewat database atau buat admin baru sesuai kebutuhan.

Upgrade role Admin Maintenance:
- Untuk database existing, import database/upgrade_v3_roles.sql satu kali.
- Setelah itu buka menu Users, tambah user dengan role Admin Maintenance.
- Role Admin Maintenance hanya punya akses ke menu Preventive Maintenance.

Catatan:
- Untuk agent per PC, buka Detail PC lalu klik Download PcNalisa PC ini.
- Jika upload otomatis agent gagal, gunakan file fallback JSON dan import lewat menu Upload Analisa JSON.
- Label QR v2 hanya menampilkan COMPANY, asset code seperti PC000123-A7, QR, dan IT Asset.
- QR tidak membuka Detail PC. QR membuka Mobile Technician Portal dan tetap memerlukan login teknisi.
- Checklist teknisi baru terbuka jika PCID, security code, schedule hari ini, dan teknisi yang ditugaskan valid.
- Setelah Save & Lock, checklist/report tidak bisa diedit. Admin dapat membuka kembali lewat Unlock Report.
- Maintenance Report dapat dibuka lalu dicetak/disimpan sebagai PDF dari browser.
- Live camera scan QR di Chrome/Android memerlukan HTTPS pada alamat IP seperti 192.168.x.x. Halaman scan akan mencoba live scanner, tetapi bila masih HTTP gunakan tombol Ambil Foto QR atau input Asset Code manual.
- Input Foto Sebelum/Sesudah memakai capture kamera mobile dan foto akan tampil di Mobile Job, Maintenance Admin, dan Report.

Cara menjalankan PcNalisa di PC Windows:
1. Di PcConnect, buka menu PC.
2. Klik Detail pada PC yang ingin dianalisa.
3. Klik Download PcNalisa PC ini.
   File yang didownload biasanya bernama seperti PcNalisa-PC000001.ps1.
4. Simpan file .ps1 tersebut di satu folder bersama tools/PcNalisa-Run.cmd.
5. Klik kanan PcNalisa-Run.cmd, pilih Run as administrator.
6. Tunggu sampai selesai. Jika berhasil, data analisa masuk otomatis ke PcConnect.
7. Jika upload otomatis gagal, ambil file fallback JSON yang dibuat di folder yang sama, lalu upload melalui menu Upload Analisa JSON.

Perintah manual jika tidak memakai launcher:
1. Buka PowerShell sebagai Administrator.
2. Masuk ke folder tempat file PcNalisa berada, contoh:
   cd "C:\PcNalisa"
3. Jalankan:
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\PcNalisa-PC000001.ps1"

Perintah manual untuk agent umum:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\PcNalisa-Agent.ps1" -PcID "PC000001" -Owner "Nama User" -ServerUrl "http://server/PcConnect/public/index.php?route=api_ingest" -Token "agent_token_di_config"

HTTPS untuk live scan kamera:
1. Cara paling rapi: pakai domain lokal/publik, misalnya asset.company.id, arahkan DNS ke server, lalu pasang sertifikat SSL di web server/NAS.
2. Jika memakai QNAP, aktifkan HTTPS/SSL Certificate dari Control Panel, lalu akses PcConnect melalui https://host-atau-domain/PcConnect/public/.
3. Jika tetap memakai IP 192.168.x.x tanpa sertifikat terpercaya, browser mobile sering tetap memblokir kamera live. Gunakan domain + certificate, atau gunakan fallback Ambil Foto QR.
4. Setelah HTTPS aktif, ubah config/config.php:
   - base_url menjadi https://domain/PcConnect/public/index.php
   - mobile_base_url menjadi https://domain/PcConnect/public/index.php?route=mobile_scan&code=

Upgrade dari paket lama:
1. Backup database.
2. Jalankan database/upgrade_v2.sql.
3. Upload ulang file aplikasi.
4. Pastikan config/config.php memiliki mobile_base_url dan company_name.

API teknisi maintenance untuk APK Android:
- Semua endpoint memakai public/index.php?route=...
- Login teknisi:
  POST route=api_technician_login
  JSON: {"username":"teknisi","password":"password","device_name":"Android"}
  Response berisi token.
- Request berikutnya pakai header:
  Authorization: Bearer TOKEN
  atau X-PcConnect-Mobile-Token: TOKEN
- Data teknisi login:
  GET route=api_technician_me
- List schedule teknisi:
  GET route=api_technician_schedules&status=open
  status bisa open, completed, atau kosong untuk semua.
- Detail schedule dan checklist:
  GET route=api_technician_schedule&id=123
- Scan QR mulai/selesai:
  POST route=api_technician_scan
  JSON: {"schedule_id":123,"phase":"start","code":"PC000001-A7","lat":-6.1,"lng":106.8}
  phase: start atau end.
- Submit checklist dan foto:
  POST multipart/form-data route=api_technician_submit
  fields:
  schedule_id=123
  done[]=1
  done[]=2
  job_notes[1]=keterangan opsional
  condition_rating=Good
  physical_condition=catatan kondisi
  additional_notes=catatan teknisi
  before_photos[]=file jpg
  after_photos[]=file jpg
- History teknisi:
  GET route=api_technician_history
- Logout token:
  POST route=api_technician_logout
