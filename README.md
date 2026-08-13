# Secure Chat Assignment

PHP + Apache + MySQL + WebSocket(Ratchet) 기반의 보안 채팅 과제용 스타터 프로젝트입니다.

## 실행

```bash
docker compose up --build
```

브라우저에서 `http://localhost:8080` 에 접속합니다.

테스트는 브라우저 두 개(또는 일반 창 + 시크릿 창)를 열고 계정 2개를 만든 뒤, 한 계정에서 다른 계정 username을 친구로 추가하면 편합니다.

## 구현 기능

- 회원가입 / 로그인 / 로그아웃
- 친구 추가
- PHP WebSocket 기반 실시간 텍스트 채팅
- 이미지 첨부
- 일반 파일 첨부 (PDF/TXT/ZIP)
- 서버 cURL 기반 URL 미리보기
- 채팅 기록 MySQL 저장

## 주요 보안 대책

- PDO native prepared statements: SQL Injection 방어
- Argon2id password hashing
- 로그인 후 session ID regeneration
- HttpOnly + SameSite=Strict session cookie
- CSRF token 검증
- sender identity를 WebSocket 인증 토큰에서 결정: message spoofing / IDOR 완화
- 친구 관계 server-side authorization 확인
- JS `textContent` 사용: Stored XSS 방어
- 업로드 MIME whitelist / 8MB 제한 / random file name / webroot 밖 저장
- 다운로드 시 sender/receiver 권한 검사
- URL preview에서 http/https만 허용, 80/443 제한, private/reserved IP 차단
- DNS 결과 전체 검사 + CURLOPT_RESOLVE를 이용한 IP pinning
- redirect를 직접 검증하며 최대 3회
- URL fetch timeout 및 1MB response limit
- WebSocket Origin 검사 및 간단한 message rate limit
- CSP / nosniff / frame-ancestors 등 보안 헤더

## 주의

`.env`는 `docker compose up` 즉시 실행을 위해 개발용 값이 포함되어 있습니다. 실제 배포에서는 반드시 새 비밀값으로 교체하고 `.env`를 Git에 커밋하지 마세요.

현재 `COOKIE_SECURE=0`은 로컬 HTTP 개발을 위한 설정입니다. HTTPS 환경에서는 `COOKIE_SECURE=1`, `APP_ORIGIN=https://...`, `WS_PUBLIC_URL=wss://...` 로 변경하세요.

## 과제 보고서에 넣기 좋은 수정 과정

1. 문자열 결합 SQL -> PDO Prepared Statement
2. 채팅 HTML 삽입 -> `textContent` 출력
3. 원본 파일명 직접 저장 -> MIME whitelist + 랜덤 파일명 + webroot 외부 저장
4. 클라이언트 `sender_id` 신뢰 -> signed short-lived WebSocket token으로 서버가 사용자 결정
5. 단순 cURL URL fetch -> scheme/port/DNS/IP/redirect 검증을 포함한 SSRF 방어
6. 상태 변경 요청 -> CSRF token 적용
7. 리소스 ID만 보고 다운로드 -> sender/receiver authorization 추가

## Git 예시

AI가 작성한 부분은 수업 지침에 맞는 공동작업자 표기를 사용하세요. 임의의 이메일 주소를 만들지 말고 담당 교수/조교가 지정한 형식이 있으면 그것을 따르세요.
