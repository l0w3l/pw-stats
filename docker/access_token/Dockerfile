FROM python:3.12-slim

WORKDIR /app

COPY scripts/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY scripts/main.py scripts/config.py scripts/auth_session.py ./

RUN mkdir -p /app/session_data

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
