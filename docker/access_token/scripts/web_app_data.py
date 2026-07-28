import urllib.parse


def extract_web_app_data(url: str) -> tuple[str, str]:
    fragment = urllib.parse.urlsplit(url).fragment
    encoded = next(
        (
            value
            for key, separator, value in (
                part.partition("=") for part in fragment.split("&")
            )
            if separator and key == "tgWebAppData"
        ),
        None,
    )

    if not encoded:
        raise ValueError("Telegram response did not contain tgWebAppData")

    return encoded, urllib.parse.unquote(encoded)
