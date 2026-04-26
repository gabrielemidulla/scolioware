import uuid
from typing import Any

from fastapi import APIRouter, File, Form, HTTPException, UploadFile

from src import persistence, storage_r2

router = APIRouter(tags=["pdf_reports"])


def _pdf_object_key(patient_id: int, report_id: int) -> str:
    return f"patients/{patient_id}/reports/{report_id}/pdfs/{uuid.uuid4().hex}.pdf"


async def upload_pdf_report(
    report_id: int = Form(...),
    physician_id: int | None = Form(None),
    physician_username: str | None = Form(None),
    title: str | None = Form(None),
    notes: str | None = Form(None),
    pdf: UploadFile = File(...),
) -> dict[str, Any]:
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 storage is not configured.")

    report = persistence.fetch_report(report_id)
    if not report:
        raise HTTPException(status_code=404, detail="Report not found.")

    body = await pdf.read()
    if not body:
        raise HTTPException(status_code=400, detail="Empty PDF upload.")

    if not body[:4] == b"%PDF":
        raise HTTPException(status_code=400, detail="Uploaded file is not a PDF.")

    patient_id = int(report["patient_id"])
    key = _pdf_object_key(patient_id, report_id)

    try:
        storage_r2.put_bytes(key, body, content_type="application/pdf")
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"R2 upload failed: {e}") from e

    pdf_report_id = persistence.insert_pdf_report(
        patient_id=patient_id,
        report_id=report_id,
        physician_id=physician_id,
        physician_username=physician_username,
        title=title,
        notes=notes,
        pdf_object_key=key,
        pdf_bytes_size=len(body),
    )

    return {
        "id": pdf_report_id,
        "report_id": report_id,
        "patient_id": patient_id,
        "object_key": key,
        "bytes": len(body),
    }


router.add_api_route("/v2/pdf-reports/upload", upload_pdf_report, methods=["POST"])


async def delete_pdf_report(pdf_report_id: int) -> dict[str, Any]:
    if not persistence.soft_delete_pdf_report(pdf_report_id):
        raise HTTPException(status_code=404, detail="PDF report not found.")
    return {"id": pdf_report_id, "deleted": True}


router.add_api_route("/pdf-reports/{pdf_report_id}", delete_pdf_report, methods=["DELETE"])
