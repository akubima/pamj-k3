# pamj-k3

Infrastruktur jaringan lokal berbasis Docker Compose untuk domain internal `k3.lan`:

- **Bind9**: DNS recursive untuk LAN sekaligus authoritative untuk zona `k3.lan`
- **chrony**: server NTP (`ntp.k3.lan`)
- **Apache + PHP**: halaman web berisi petunjuk setting NTP client (Windows & Linux) dan pengukur time offset komputer terhadap server

## Struktur

```
pamj-k3/
├── docker-compose.yml   # bind9, ntp, web; konfigurasi DNS & chrony inline (configs:)
├── .env.example         # contoh variabel lingkungan
├── LICENSE
├── README.md
└── web/
    └── html/
        ├── index.html   # panduan NTP Windows/Linux + widget time offset
        └── time.php     # endpoint waktu server (ms) untuk widget
```

## Prasyarat

- Docker Compose v2.23.1 atau lebih baru (`docker compose version`), karena memakai `configs: content:`
- `SERVER_IP` adalah IP milik host yang menjalankan Docker
- Port 53 (TCP/UDP), 123 (UDP), dan 80 (TCP) pada `SERVER_IP` tidak dipakai layanan lain (mis. chrony/ntpd bawaan host atau DNS server lain)

## Konfigurasi

```bash
cp .env.example .env
```

```env
SERVER_IP=10.101.3.179     # IP LAN host Docker
TZ=Asia/Jakarta
# ZONE_SERIAL=1            # naikkan setiap kali record DNS diubah
```

## Menjalankan

```bash
docker compose up -d --build
docker compose ps
docker logs k3-bind9 2>&1 | tail -20     # harus ada: zone k3.lan/IN: loaded serial ...
```

Arahkan DNS klien ke `SERVER_IP`, lalu buka http://ntp.k3.lan

## Komponen

| Service | Container | Port | Fungsi |
|---|---|---|---|
| `bind9` | k3-bind9 | 53/tcp+udp | DNS recursive untuk LAN + zona `k3.lan` |
| `ntp` | k3-ntp | 123/udp | Server NTP (chrony), upstream `*.id.pool.ntp.org` dan `*.asia.pool.ntp.org` |
| `web` | k3-web | 80/tcp | Apache + PHP (`php:8.3-apache`), melayani semua hostname |

Record zona `k3.lan`: `ns`, `@`, `www`, `ntp`, semuanya mengarah ke `SERVER_IP`.

## Mengubah record DNS

1. Edit blok `zone_k3_lan` pada bagian `configs:` di `docker-compose.yml`
2. Naikkan `ZONE_SERIAL` di `.env` (mis. 1 menjadi 2)
3. `docker compose up -d` (container dibuat ulang otomatis karena config berubah)

Di dalam `content:`, tanda `$` harus ditulis `$$` (mis. `$$TTL`), karena Compose memproses `$`.

## Pengujian

### DNS (Windows)

```
nslookup ntp.k3.lan 10.101.3.179        # harus menjawab SERVER_IP
nslookup example.com 10.101.3.179       # tes recursion
```

### NTP dari Windows (tanpa mengubah setting)

```
w32tm /stripchart /computer:ntp.k3.lan /samples:5 /dataonly
w32tm /monitor /computers:ntp.k3.lan
```

Nilai `+` berarti jam komputer lebih lambat dari server, `-` berarti lebih cepat.

### NTP dari Linux

```bash
chronyc -h 10.101.3.179 sources         # atau: ntpdate -q ntp.k3.lan
sntp ntp.k3.lan
```

### Di host server

```bash
docker exec k3-ntp chronyc tracking
docker exec k3-ntp chronyc -n sources
```

### Web

Buka http://ntp.k3.lan. Widget menampilkan offset jam komputer terhadap server lewat HTTP (akurasi beberapa sampai puluhan ms). Untuk presisi tinggi gunakan `w32tm` atau `chronyc`.

## Troubleshooting

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| `Server failed` saat resolve `k3.lan`, tetapi `google.com` jalan | Zona gagal dimuat | `docker logs k3-bind9`, cek `$$TTL` dan sintaks zona |
| `docker logs k3-bind9` kosong | Log tidak diarahkan ke stderr | Pastikan blok `logging` ada di `named_conf` |
| `w32tm`: `0x800705B4` / timeout | UDP 123 tidak sampai | Buka inbound UDP 123 di firewall host; cek `docker ps` |
| `w32tm`: `0x80072AF9` | DNS klien belum ke `SERVER_IP` | Set DNS adapter ke `SERVER_IP` atau tes dengan IP langsung |
| Widget web "Gagal mengukur" | `time.php` tidak dijalankan PHP | Pastikan image `php:8.3-apache` dan file ada di `web/html` |
| Port 53 / 123 gagal di-bind | Layanan lain memakai port | Hentikan layanan tersebut, atau pastikan `SERVER_IP` benar |

## Catatan

- Container chrony memakai `cap_add: SYS_TIME`, sehingga ikut mengoreksi jam host (jam kernel dibagi dengan container). Pada Docker Desktop (WSL2/Hyper-V), yang terkoreksi adalah jam VM Docker, bukan jam Windows.
- `.lan` tidak dipakai mDNS sehingga aman untuk jaringan internal, tetapi bukan TLD resmi: jangan dipakai untuk sertifikat dari CA publik.
- Bind9 me-resolve langsung dari root server. Untuk meneruskan ke upstream, tambahkan di blok `options` pada `named_conf`: `forwarders { 1.1.1.1; 8.8.8.8; }; forward only;`
- Peringatan Apache `Could not reliably determine the server's fully qualified domain name` di `docker logs k3-web` normal dan bisa diabaikan.
- Recursion dan query dibatasi ke ACL `lan` (RFC1918 + localnets). Jangan membuka port 53 ke internet.

## Lisensi

[MIT](LICENSE)
