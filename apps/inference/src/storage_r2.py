import os
from functools import lru_cache

import boto3
from botocore.client import BaseClient
from botocore.config import Config


def r2_configured() -> bool:
    return all(
        os.environ.get(k)
        for k in (
            "R2_ENDPOINT_URL",
            "R2_ACCESS_KEY_ID",
            "R2_SECRET_ACCESS_KEY",
            "R2_BUCKET_NAME",
        )
    )


@lru_cache(maxsize=1)
def _client() -> BaseClient:
    if not r2_configured():
        raise RuntimeError(
            "R2 is not configured. Set R2_ENDPOINT_URL, R2_ACCESS_KEY_ID, "
            "R2_SECRET_ACCESS_KEY, and R2_BUCKET_NAME."
        )
    return boto3.client(
        "s3",
        endpoint_url=os.environ["R2_ENDPOINT_URL"].rstrip("/"),
        aws_access_key_id=os.environ["R2_ACCESS_KEY_ID"],
        aws_secret_access_key=os.environ["R2_SECRET_ACCESS_KEY"],
        region_name="auto",
        config=Config(signature_version="s3v4"),
    )


def bucket() -> str:
    return os.environ["R2_BUCKET_NAME"]


def put_bytes(key: str, body: bytes, content_type: str = "application/octet-stream") -> None:
    _client().put_object(Bucket=bucket(), Key=key, Body=body, ContentType=content_type)


def get_bytes(key: str) -> bytes:
    resp = _client().get_object(Bucket=bucket(), Key=key)
    return resp["Body"].read()


def presigned_get_url(key: str, expires_in: int = 3600) -> str | None:
    if not key:
        return None
    return _client().generate_presigned_url(
        "get_object",
        Params={"Bucket": bucket(), "Key": key},
        ExpiresIn=expires_in,
    )


def object_key_original(patient_id: int, report_id: int) -> str:
    return f"patients/{patient_id}/reports/{report_id}/original.jpeg"


def object_key_computed(patient_id: int, report_id: int) -> str:
    return f"patients/{patient_id}/reports/{report_id}/computed.jpeg"
