import trafilatura

def scrape_content(url):
    try:
        downloaded = trafilatura.fetch_url(url)
        if not downloaded:
            return None
        text = trafilatura.extract(downloaded)

        if not text:
            return None

        return text

    except Exception:
        return None