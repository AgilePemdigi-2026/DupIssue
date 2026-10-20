import logging
from contextlib import asynccontextmanager
from typing import AsyncIterator

from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel, StrictInt, StrictStr, field_validator
from sentence_transformers import SentenceTransformer, util


MODEL_NAME = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
logger = logging.getLogger(__name__)


class Issue(BaseModel):
    title: str
    description: str

    @field_validator("title", "description")
    @classmethod
    def must_not_be_empty(cls, value: str) -> str:
        value = value.strip()
        if not value:
            raise ValueError("Field must not be empty.")
        return value


class SimilarityRequest(BaseModel):
    issue1: Issue
    issue2: Issue


IssueId = StrictStr | StrictInt


class NewIssue(Issue):
    issue_id: IssueId

    @field_validator("issue_id")
    @classmethod
    def id_must_not_be_empty(cls, value: IssueId) -> IssueId:
        if isinstance(value, str) and not value.strip():
            raise ValueError("Issue ID must not be empty.")
        return value


class CandidateIssue(NewIssue):
    project_id: IssueId

    @field_validator("project_id")
    @classmethod
    def project_id_must_not_be_empty(cls, value: IssueId) -> IssueId:
        if isinstance(value, str) and not value.strip():
            raise ValueError("Project ID must not be empty.")
        return value


class CandidatesRequest(BaseModel):
    project_id: IssueId
    new_issue: NewIssue
    candidates: list[CandidateIssue]

    @field_validator("project_id")
    @classmethod
    def project_id_must_not_be_empty(cls, value: IssueId) -> IssueId:
        if isinstance(value, str) and not value.strip():
            raise ValueError("Project ID must not be empty.")
        return value


def combine_issue_text(issue: Issue) -> str:
    """Build consistent text input from an issue's title and description."""
    return f"Title: {issue.title}\nDescription: {issue.description}"


def ids_match(first: IssueId, second: IssueId) -> bool:
    """Compare IDs consistently when input uses either strings or integers."""
    return str(first) == str(second)


@asynccontextmanager
async def lifespan(application: FastAPI) -> AsyncIterator[None]:
    """Load the NLP model once when the application starts."""
    try:
        application.state.model = SentenceTransformer(MODEL_NAME, device="cpu")
    except Exception:
        logger.exception("Could not load the NLP model.")
        raise RuntimeError("NLP model could not be loaded.") from None

    yield


app = FastAPI(title="DupIssue NLP Service", lifespan=lifespan)


@app.get("/health")
def health_check():
    """Return the service health status."""
    return {
        "status": "ok",
        "service": "DupIssue NLP Service",
    }


@app.post("/similarity")
def compare_similarity(payload: SimilarityRequest, request: Request):
    """Return a semantic similarity score for two issues."""
    try:
        texts = [combine_issue_text(payload.issue1), combine_issue_text(payload.issue2)]
        embeddings = request.app.state.model.encode(
            texts,
            convert_to_tensor=True,
            show_progress_bar=False,
        )
        score = float(util.cos_sim(embeddings[0], embeddings[1]).item())
    except Exception:
        logger.exception("Could not calculate issue similarity.")
        raise HTTPException(
            status_code=503,
            detail="Similarity service is temporarily unavailable.",
        ) from None

    return {"similarity": score}


@app.post("/candidates")
def find_candidates(payload: CandidatesRequest, request: Request):
    """Rank same-project candidate issues by semantic similarity."""
    eligible_candidates = [
        candidate
        for candidate in payload.candidates
        if ids_match(candidate.project_id, payload.project_id)
        and not ids_match(candidate.issue_id, payload.new_issue.issue_id)
    ]

    if not eligible_candidates:
        return {"candidates": []}

    try:
        issues = [payload.new_issue, *eligible_candidates]
        texts = [combine_issue_text(issue) for issue in issues]
        embeddings = request.app.state.model.encode(
            texts,
            convert_to_tensor=True,
            show_progress_bar=False,
        )
        scores = util.cos_sim(embeddings[0], embeddings[1:])[0].tolist()
        results = [
            {"issue_id": candidate.issue_id, "similarity": float(score)}
            for candidate, score in zip(eligible_candidates, scores)
        ]
        results.sort(key=lambda result: result["similarity"], reverse=True)
    except Exception:
        logger.exception("Could not calculate candidate similarities.")
        raise HTTPException(
            status_code=503,
            detail="Similarity service is temporarily unavailable.",
        ) from None

    return {"candidates": results}
