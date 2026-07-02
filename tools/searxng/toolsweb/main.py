from fastapi import FastAPI
from pydantic import BaseModel

from services.search import search_web
from services.scraper import scrape_content
from services.cleaner import clean_text

app = FastAPI()

class SearchRequest(BaseModel):
    query: str

@app.get("/")
def home():
    return {"message": "Python Tools API Running"}

@app.post("/search-web")
def search_api(req: SearchRequest):
    results = search_web(req.query)

    if isinstance(results, dict) and results.get("error"):
        return results

    final_results = []

    for item in results:
        url = item["url"]

        scraped = scrape_content(url)
        cleaned = clean_text(scraped)

        if not cleaned:
            continue

        final_results.append({
            "title": item["title"],
            "url": url,
            "content": cleaned
        })

    return {
        "query": req.query,
        "results": final_results
    }

# jalankan "uvicorn main:app --reload --port 8001"
# pip install fastapi uvicorn requests trafilatura