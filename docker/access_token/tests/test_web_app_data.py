import sys
import unittest
import urllib.parse
from pathlib import Path

SCRIPTS_DIRECTORY = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIRECTORY))

from web_app_data import extract_web_app_data  # noqa: E402


class ExtractWebAppDataTest(unittest.TestCase):
    def test_extracts_only_init_data_from_a_multi_parameter_fragment(self) -> None:
        decoded = (
            'user={"id":1,"first_name":"Test+User"}'
            "&auth_date=1785218447"
            "&signature=sig%2B%2F%3D"
            "&hash=hash%26value"
        )
        encoded = urllib.parse.quote(decoded, safe="")
        url = (
            f"https://example.invalid/#tgWebAppData={encoded}"
            "&tgWebAppVersion=8.0&tgWebAppPlatform=android"
        )

        raw, actual = extract_web_app_data(url)

        self.assertEqual(raw, encoded)
        self.assertEqual(actual, decoded)

    def test_finds_init_data_when_it_is_not_the_first_fragment_parameter(self) -> None:
        encoded = "auth_date%3D1785218447%26hash%3Dsigned"
        url = (
            "https://example.invalid/#tgWebAppVersion=8.0"
            f"&tgWebAppData={encoded}&tgWebAppPlatform=android"
        )

        self.assertEqual(
            extract_web_app_data(url),
            (encoded, "auth_date=1785218447&hash=signed"),
        )

    def test_preserves_plus_characters_during_the_single_decode(self) -> None:
        encoded = "query_id%3DAAE%2Bvalue%26hash%3Dabc%252Bdef"

        _, decoded = extract_web_app_data(
            f"https://example.invalid/#tgWebAppData={encoded}"
        )

        self.assertEqual(decoded, "query_id=AAE+value&hash=abc%2Bdef")

    def test_rejects_missing_or_empty_init_data(self) -> None:
        invalid_urls = [
            "https://example.invalid/",
            "https://example.invalid/#tgWebAppVersion=8.0",
            "https://example.invalid/#tgWebAppData=&tgWebAppVersion=8.0",
        ]

        for url in invalid_urls:
            with self.subTest(url=url):
                with self.assertRaisesRegex(ValueError, "tgWebAppData"):
                    extract_web_app_data(url)


if __name__ == "__main__":
    unittest.main()
