# G2B Crawler
![2026-02-235 12 35-ezgif com-video-to-gif-converter](https://github.com/user-attachments/assets/224aa007-16b7-423e-935b-8883d46e5ab4)

나라장터(G2B)·K-스타트업·중소벤처24·기업마당 공고를 API로 수집해 DB에 저장하고, 웹에서 조회·필터·엑셀 다운로드할 수 있는 모니터링 도구입니다.

## 기능

- **수집**: 나라장터, K-스타트업, 중소벤처24, 기업마당 API 연동 (증분 수집 지원)
- **조회**: 테이블 리스트, 출처/마감/키워드 필터, 최신순·마감일순·금액순 정렬
- **키워드**: 등록한 키워드와 매칭된 공고, 태그 필터
- **스크랩**: 관심 공고 북마크 (localStorage)
- **엑셀**: 목록/선택 건 엑셀 다운로드

## 요구 사항

- PHP 8.0+
- MySQL 5.7+ (또는 MariaDB)
- API 키 (나라장터, K-스타트업, 중소벤처24, 기업마당 각각 발급)

## 설치

1. **저장소 클론**
   ```bash
   git clone https://github.com/wlghksw/Bidding_Monitor.git
   cd Bidding_Monitor
   ```

2. **설정 파일**
   ```bash
   cp config.example.php config.php
   ```
   `config.php`에서 DB 접속 정보와 API 키를 입력합니다.

3. **DB 생성 및 마이그레이션**
   ```bash
   mysql -u root -p < schema.sql
   php migrate.php
   ```

## 실행

- **웹 서버** (로컬 확인용)
  ```bash
  php -S localhost:5050
  ```
  브라우저에서 http://localhost:5050 접속

- **수집 실행** (수동)
  ```text
  http://localhost:5050/fetch.php?manual=1
  ```
  또는 터미널: `php fetch.php`

- **자동 수집** (cron 예시, 매일 9시)
  ```text
  0 9 * * * php /path/to/Bidding_Monitor/fetch.php
  ```

## 설정 요약

| 항목 | 설명 |
|------|------|
| `config.php` | DB, API 키, 수집 기간/페이지 수 (git 제외) |
| `G2B_DAYS` | 나라장터 전체 수집 시 조회 기간(일) |
| `G2B_INCREMENTAL_DAYS` | 나라장터 증분 수집 시 조회 기간(일) |
| `KSTARTUP_INCREMENTAL_PAGES` | K-스타트업 증분 수집 시 페이지 수 |
| `SMES24_DAYS` | 중소벤처24 조회 기간(일) |
| `BIZINFO_API_KEY` | 기업마당 서비스키 (crtfcKey) |

