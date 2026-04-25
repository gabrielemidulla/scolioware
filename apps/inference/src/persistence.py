import json
import os
from contextlib import contextmanager
from typing import Any, Optional

import pymysql
from pymysql.cursors import DictCursor

from src.curve_type import normalize_curve_type


def _conn_params():
    return dict(
        host=os.environ.get("MYSQL_HOST", "127.0.0.1"),
        port=int(os.environ.get("MYSQL_PORT", "3306")),
        user=os.environ.get("MYSQL_USER", "root"),
        password=os.environ.get("MYSQL_PASSWORD", ""),
        database=os.environ.get("MYSQL_DATABASE", "php_commerce"),
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
    )


@contextmanager
def get_conn():
    conn = pymysql.connect(**_conn_params())
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def patient_exists(patient_id: int) -> bool:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute("SELECT 1 FROM patients WHERE id = %s LIMIT 1", (patient_id,))
        return cur.fetchone() is not None


def insert_report_pending(
    patient_id: int,
    height_cm: Optional[float] = None,
    weight_kg: Optional[float] = None,
) -> int:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO reports (patient_id, height_cm, weight_kg, status) VALUES (%s, %s, %s, 'pending')",
            (patient_id, height_cm, weight_kg),
        )
        return int(cur.lastrowid)


def refresh_patient_last_vitals(patient_id: int) -> None:
    """Set patients.last_* from the newest report that has both height and weight."""
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            """
            SELECT height_cm, weight_kg FROM reports
            WHERE patient_id = %s AND height_cm IS NOT NULL AND weight_kg IS NOT NULL
            ORDER BY created_at DESC, id DESC
            LIMIT 1
            """,
            (patient_id,),
        )
        row = cur.fetchone()
        if row:
            cur.execute(
                "UPDATE patients SET last_height_cm = %s, last_weight_kg = %s WHERE id = %s",
                (row["height_cm"], row["weight_kg"], patient_id),
            )
        else:
            cur.execute(
                "UPDATE patients SET last_height_cm = NULL, last_weight_kg = NULL WHERE id = %s",
                (patient_id,),
            )


def set_original_key(report_id: int, key: str) -> None:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            "UPDATE reports SET original_object_key = %s WHERE id = %s",
            (key, report_id),
        )


def claim_next_pending() -> Optional[dict[str, Any]]:
    """Atomically move one pending row to processing; return row or None."""
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            """
            SELECT id, patient_id, original_object_key
            FROM reports
            WHERE status = 'pending' AND original_object_key IS NOT NULL
            ORDER BY id ASC
            LIMIT 1
            """
        )
        row = cur.fetchone()
        if not row:
            return None
        cur.execute(
            "UPDATE reports SET status = 'processing' WHERE id = %s AND status = 'pending'",
            (row["id"],),
        )
        if cur.rowcount != 1:
            return None
        return dict(row)


def mark_failed(report_id: int, message: str) -> None:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            "UPDATE reports SET status = 'failed', error_message = %s WHERE id = %s",
            (message[:65000], report_id),
        )


def mark_completed(
    report_id: int,
    computed_key: str,
    detections: Any,
    landmarks: Any,
    angles: Any,
    midpoint_lines: Any,
    curve_type: Optional[str],
    derived: Optional[dict[str, Any]] = None,
) -> None:
    d = derived or {}
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            """
            UPDATE reports SET
              status = 'completed',
              computed_object_key = %s,
              detections_json = %s,
              landmarks_json = %s,
              angles_json = %s,
              midpoint_lines_json = %s,
              curve_type = %s,
              cobb_pt_deg = %s,
              cobb_mt_deg = %s,
              cobb_tl_deg = %s,
              cobb_thoracic_deg = %s,
              cobb_lumbar_deg = %s,
              cobb_max_region = %s,
              cobb_max_deg = %s,
              cobb_max_vert_superior = %s,
              cobb_max_vert_inferior = %s,
              image_width = %s,
              image_height = %s,
              report_metadata_json = %s,
              error_message = NULL
            WHERE id = %s
            """,
            (
                computed_key,
                json.dumps(detections, default=_json_default) if detections is not None else None,
                json.dumps(landmarks, default=_json_default) if landmarks is not None else None,
                json.dumps(angles, default=_json_default) if angles is not None else None,
                json.dumps(midpoint_lines, default=_json_default)
                if midpoint_lines is not None
                else None,
                normalize_curve_type(curve_type),
                d.get("cobb_pt_deg"),
                d.get("cobb_mt_deg"),
                d.get("cobb_tl_deg"),
                d.get("cobb_thoracic_deg"),
                d.get("cobb_lumbar_deg"),
                d.get("cobb_max_region"),
                d.get("cobb_max_deg"),
                d.get("cobb_max_vert_superior"),
                d.get("cobb_max_vert_inferior"),
                d.get("image_width"),
                d.get("image_height"),
                d.get("report_metadata_json"),
                report_id,
            ),
        )


def _json_default(o):
    try:
        import numpy as np

        if isinstance(o, np.floating):
            return float(o)
        if isinstance(o, np.integer):
            return int(o)
        if isinstance(o, np.ndarray):
            return o.tolist()
    except ImportError:
        pass
    raise TypeError(type(o))


def fetch_report(report_id: int) -> Optional[dict[str, Any]]:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute("SELECT * FROM reports WHERE id = %s", (report_id,))
        row = cur.fetchone()
        return dict(row) if row else None


def insert_pdf_report(
    *,
    patient_id: int,
    report_id: int,
    physician_id: Optional[int],
    physician_username: Optional[str],
    title: Optional[str],
    notes: Optional[str],
    pdf_object_key: str,
    pdf_bytes_size: Optional[int],
) -> int:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            """
            INSERT INTO pdf_reports (
              patient_id, report_id, physician_id, physician_username,
              title, notes, pdf_object_key, pdf_bytes_size
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                patient_id,
                report_id,
                physician_id,
                (physician_username or None),
                (title or None),
                (notes or None),
                pdf_object_key,
                pdf_bytes_size,
            ),
        )
        return int(cur.lastrowid)


def fetch_pdf_report(pdf_report_id: int) -> Optional[dict[str, Any]]:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            "SELECT * FROM pdf_reports WHERE id = %s AND deleted_at IS NULL",
            (pdf_report_id,),
        )
        row = cur.fetchone()
        return dict(row) if row else None


def soft_delete_pdf_report(pdf_report_id: int) -> bool:
    with get_conn() as conn:
        cur = conn.cursor()
        cur.execute(
            "UPDATE pdf_reports SET deleted_at = CURRENT_TIMESTAMP "
            "WHERE id = %s AND deleted_at IS NULL",
            (pdf_report_id,),
        )
        return cur.rowcount == 1
