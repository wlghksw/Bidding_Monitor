# 입찰 공고 모니터링 시스템 - 요구사항 정의서

> 전체 서비스 기능 및 토대를 정의하는 기획/요구사항 문서  
> 팀 공유용

---

## 1. 전체 서비스 목표

- **정부·공공기관 입찰 공고**(나라장터, K-Startup 등)를 **자동으로 수집**
- 사용자가 웹에서 **카드형 리스트 형태**로 편하게 조회·검색
- 필요 시 공고 목록을 **엑셀로 추출**할 수 있는 웹 서비스 구현

---

## 2. 데이터 수집 방식 (API 연동 개념)

### 공공데이터 OpenAPI 활용

- 나라장터(조달청) 입찰공고, K-Startup 창업지원 공고 등을 OpenAPI로 조회

### Pull 방식 구조

- API는 **push**(우리 서버로 데이터를 밀어주는) 방식이 아님
- 우리가 **주기적으로 호출**(pull)해서 최신 공고를 가져오는 구조

### 배치/크론 작업

- 하루 1회 또는 1시간마다 API 호출
- **"어제 이후 / 최근 N시간"** 기준으로 신규 또는 변경된 공고만 조회
- 내부 DB에 **INSERT/UPDATE** 처리

### 예상 흐름

1. 공공데이터 포털에서 각 API 서비스에 활용 신청 → 인증키 발급
2. PHP 배치 스크립트에서 인증키로 REST 호출
3. 응답(JSON/XML) 파싱 후 공고 테이블에 저장
4. 웹 화면은 **DB 조회**로 리스트 표시 (API에 직접 붙지 않음)

---

## 3. 화면(UI) 설계 – 카드형 리스트

### 3-1. 전체 레이아웃

| 영역 | 내용 |
|------|------|
| **상단** | 탭 형태 – 소스별 탭 + 공고 건수 표시 |
| **좌측** | 필터 패널 |
| **우측** | 공고 카드 리스트 |

### 3-2. 상단 탭

- 예) [나라장터] [법무부통합연구지원시스템] 등 **소스별 탭**
- 각 탭에 **공고 건수** 표시

### 3-3. 좌측 필터 패널

| 필터 항목 | 옵션 예시 |
|-----------|------------|
| **공고 상태** | 전체 / 발주계획 / 입찰공고 / 개찰결과 … |
| **업무구분** | 공사 / 용역 / 물품 / 외자 등 |
| **태깅 검색** | 업종·분류 태그 기반 필터 |
| **검색 기간** | 당일, 1주일, 1개월, 6개월, 1년, 직접 일자 입력 |
| **공고명** | 검색창 |

### 3-4. 우측 공고 카드 리스트

각 공고를 **하나의 카드**로 표시. 카드에 포함될 필드:

| 필드 | 설명 |
|------|------|
| 공고명 | 클릭 시 원문 사이트로 이동 |
| 기관명, 지역 | |
| 추정금액 | 예: 81,800,000원 |
| 업무구분, 계약방식 | |
| 공고게시일, 마감일 | |
| 스크랩/관심 공고 아이콘 | |

### 3-5. 구현 방식 (PHP + HTML/CSS)

- 컨트롤러에서 DB 조회 결과를 배열로 뷰에 전달
- 뷰에서 `foreach`로 카드 반복 렌더링
- 필터/검색 조건은 **GET 파라미터**로 전달

**URL 예시**

```
/bids/list.php?status=입찰공고&keyword=네트워크&from=2026-02-01&to=2026-02-21
```

---

## 4. 검색·키워드 기능 설계

### 4-1. 초기 구현: RDBMS 기반 검색

#### 데이터베이스

- MySQL / PostgreSQL 등 RDBMS
- 주요 컬럼에 인덱스: `title`, `agency_name`, `bid_type`, `notice_date`, `tags` 등

#### 검색 방식

| 유형 | 방식 |
|------|------|
| 공고명/기관명 | LIKE 또는 FULLTEXT 인덱스 |
| 기간 | `WHERE notice_date BETWEEN :from AND :to` |
| 상태/업무구분/태그 | `WHERE status = ? AND category = ?` |

#### 키워드 기준 필터링

- **1단계: 룰 기반 단순화**
  - 사전에 키워드 리스트 관리 (예: 클라우드, 네트워크, 정보보안, SI, 관제 등)
  - 공고명/내용에 해당 키워드가 포함되면 태그 컬럼에 저장
  - 좌측 "태깅검색" 영역은 이 태그로 필터링

### 4-2. 오타 대응 전략

#### 단순 키워드 매칭 한계

- 문자열 포함 검색만 할 경우 오타 시 결과 미노출  
  예: "네트워크" 대신 "네트웍"

#### UX 전략

| 전략 | 설명 |
|------|------|
| **자동완성 & 추천 키워드** | 검색창에 일부만 입력해도 키워드 목록에서 추천 표시, 클릭으로 검색 |
| **키워드 버튼/태그 UI** | 상단/좌측에 인기 키워드를 버튼으로 노출, 클릭 시 필터링 |
| **동의어/표기 변형 사전** | 내부 사전으로 네트워크↔네트웍, AI↔인공지능 등 표준화 후 검색 |

---

## 5. 엘라스틱서치 도입 시점

### 5-1. 도입이 적합한 경우

- 공고 데이터가 **수십만~수백만 건** 이상
- 검색 고도화 필요:
  - 공고명/내용 전체 텍스트 검색
  - 연관도 점수 정렬, 하이라이팅
  - 자동완성, 유사 공고/연관 키워드 추천
- 텍스트 기반 키워드 추출·분석 필요 (terms aggregation, n-gram, Fuzzy 검색)

### 5-2. 초기(MVP)에는 미도입 권장

- 데이터량 적음
- 검색 조건이 단순 (공고명/기관명/기간/태그 위주)
- **RDBMS + 인덱스 + FULLTEXT**로 충분
- 운영·인프라 복잡도 고려 시, 수요 생길 때 도입 검토

### 5-3. 단계적 로드맵

| 단계 | 내용 |
|------|------|
| **1단계 (MVP)** | MySQL/PostgreSQL, LIKE/FULLTEXT, 사전 키워드, 자동완성/추천/태그 버튼 |
| **2단계 (고도화)** | 엘라스틱서치/OpenSearch 도입, Fuzzy·동의어·하이라이팅·추천 등 |

---

## 6. 엑셀 추출 기능

- 리스트 페이지에 **"엑셀 다운로드"** 버튼 제공
- 현재 검색/필터 조건을 그대로 적용해 DB에서 재조회
- PHPSpreadsheet 등으로 엑셀 생성
- **컬럼 예시**: 공고명, 공고번호, 기관명, 지역, 금액, 상태, 공고일, 마감일 등

---

## 7. 구현 순서 요약

1. 공공데이터 OpenAPI 활용 신청 → 인증키 발급
2. DB 테이블 설계 (입찰공고, 태그/키워드, 스크랩 등)
3. 배치/크론 스크립트로 주기적 공고 수집·갱신
4. **카드형 리스트 + 좌측 필터 UI** 구현
5. RDBMS 기반 검색 + 자동완성/추천 키워드/태그 버튼으로 오타 최소화
6. 엑셀 다운로드 기능 추가
7. 트래픽·데이터 증가 시 **엘라스틱서치 도입 검토**

---

## 8. DB 테이블 예시 설계안

```sql
-- 입찰 공고
CREATE TABLE bids (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(500) NOT NULL,
  url           TEXT NOT NULL,
  source        VARCHAR(50) NOT NULL,      -- 나라장터, K-스타트업, IITP 등
  org_name      VARCHAR(200),
  region        VARCHAR(100),              -- 지역 (추가)
  budget        VARCHAR(100),
  budget_raw    BIGINT DEFAULT 0,
  bid_type      VARCHAR(50),               -- 업무구분: 공사/용역/물품/외자 (추가)
  status        VARCHAR(50),               -- 발주계획/입찰공고/개찰결과 (추가)
  notice_date   DATE,                      -- 공고게시일
  deadline_date DATE,
  fetched_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_title_source (title(200), source)
);

-- 키워드
CREATE TABLE keywords (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  keyword    VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 공고-키워드 매칭 (태깅)
CREATE TABLE bid_keywords (
  bid_id     INT NOT NULL,
  keyword_id INT NOT NULL,
  PRIMARY KEY (bid_id, keyword_id),
  FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE,
  FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
);

-- 스크랩/관심 공고 (추가)
CREATE TABLE bookmarks (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  bid_id     INT NOT NULL,
  user_id    INT,                          -- (로그인 연동 시)
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bid_user (bid_id, user_id),
  FOREIGN KEY (bid_id) REFERENCES bids(id) ON DELETE CASCADE
);

-- 동의어 사전 (추가, 4-2 동의어 전략용)
CREATE TABLE synonyms (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  word     VARCHAR(100) NOT NULL,
  synonym  VARCHAR(100) NOT NULL
);
```

---

## 9. 리스트 페이지 요청/응답 파라미터 규격

### 9-1. 요청 (GET)

| 파라미터 | 타입 | 설명 |
|----------|------|------|
| `source` | string | 출처: 나라장터, K-스타트업, IITP, 중소기업기술정보진흥원 등 |
| `status` | string | 공고 상태: 전체, 발주계획, 입찰공고, 개찰결과 |
| `bid_type` | string | 업무구분: 공사, 용역, 물품, 외자 |
| `keyword` | string | 태그/키워드 ID 또는 검색어 |
| `search` | string | 공고명/기관명 자유 검색 |
| `from` | date | 기간 시작 (YYYY-MM-DD) |
| `to` | date | 기간 종료 (YYYY-MM-DD) |
| `deadline` | int | 마감 N일 이내 (3, 7, 30 등) |
| `sort` | string | 정렬: newest, deadline, amount |
| `page` | int | 페이지 번호 (기본 1) |
| `per_page` | int | 페이지당 건수 (기본 20) |

### 9-2. URL 예시

```
index.php?source=나라장터&status=입찰공고&keyword=네트워크&from=2026-02-01&to=2026-02-21&sort=newest&page=1
```

---

## 10. 현재 구현 상태 vs 요구사항

| 항목 | 요구사항 | 현재 |
|------|----------|------|
| API 수집 | 나라장터, K-Startup 등 | ✅ 나라장터, K-스타트업, SMTECH, IITP |
| 배치/크론 | 주기적 수집 | ✅ fetch.php (수동/크론) |
| 상단 탭 | 소스별 탭 + 건수 | ✅ 출처별 건수 (좌측 필터) |
| 좌측 필터 | 공고상태, 업무구분, 기간, 태깅 | ✅ 출처, 마감, 태그 검색, 공고명 검색 |
| 카드형 리스트 | 카드 UI | ✅ 카드 그리드 |
| 스크랩/관심 | 아이콘 | ✅ localStorage 기반 북마크 (☆/★) |
| 자동완성/추천 키워드 | 검색창 | ✅ api_suggest.php + 검색창 연동 |
| 동의어 사전 | 표준화 검색 | ⏳ 추후 |
| 엑셀 다운로드 | ✅ | ✅ |
