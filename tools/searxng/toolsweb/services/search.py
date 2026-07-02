import requests

SEARXNG_URL = "http://localhost:8085/search"

def search_web(query):
    try:
        response = requests.get(SEARXNG_URL, params={
        "q": query,
        "format": "json"
        }, timeout=30)

        data = response.json()
        results = data.get("results", [])

        final_results = []

        for item in results[:5]:
            final_results.append({
                "title": item.get("title"),
                "url": item.get("url"),
                "engine": item.get("engine")
            })

        return final_results

    except Exception as e:
        return {"error": str(e)}