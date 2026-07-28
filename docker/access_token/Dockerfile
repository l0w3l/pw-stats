FROM python:3.12-slim-bookworm AS base

WORKDIR /app

COPY scripts/requirements.txt .
RUN pip install --no-cache-dir --disable-pip-version-check -r requirements.txt

COPY scripts/main.py scripts/config.py scripts/auth_session.py ./

FROM base AS test

COPY scripts/requirements-dev.txt ./
RUN pip install --no-cache-dir --disable-pip-version-check -r requirements-dev.txt
COPY tests/ ./tests/
CMD ["pytest", "-q", "tests"]

FROM base AS runtime

RUN groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --no-create-home --home-dir /app app \
    && mkdir -p /app/session_data \
    && chown -R app:app /app

USER 10001:10001

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--limit-concurrency", "16", "--timeout-keep-alive", "5", "--no-access-log"]
