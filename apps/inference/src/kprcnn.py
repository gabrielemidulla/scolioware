from __future__ import annotations

import os

import numpy as np
import torch
import torchvision
from torchvision.transforms import functional as F

from src.get_model import get_kprcnn_model

_model = None


def _get_model_cached():
    global _model
    if _model is None:
        _model = get_kprcnn_model()
    return _model


def _filter_output(output):
  scores = output['scores'].detach().cpu().numpy()

  high_scores_idxs = np.where(scores > 0.5)[0].tolist()

  post_nms_idxs = torchvision.ops.nms(output['boxes'][high_scores_idxs], output['scores'][high_scores_idxs], 0.3).cpu().numpy()

  np_keypoints = output['keypoints'][high_scores_idxs][post_nms_idxs].detach().cpu().numpy()
  np_bboxes = output['boxes'][high_scores_idxs][post_nms_idxs].detach().cpu().numpy()
  np_scores = output['scores'][high_scores_idxs][post_nms_idxs].detach().cpu().numpy()

  sorted_scores_idxs = np.argsort(-1*np_scores)

  np_scores = scores[sorted_scores_idxs][:18]
  np_keypoints = np.array([np_keypoints[idx] for idx in sorted_scores_idxs])[:18]
  np_bboxes = np.array([np_bboxes[idx] for idx in sorted_scores_idxs])[:18]

  ymins = np.array([kps[0][1] for kps in np_keypoints])

  sorted_ymin_idxs = np.argsort(ymins)

  np_scores = np.array([np_scores[idx] for idx in sorted_ymin_idxs])
  np_keypoints = np.array([np_keypoints[idx] for idx in sorted_ymin_idxs])
  np_bboxes = np.array([np_bboxes[idx] for idx in sorted_ymin_idxs])

  keypoints_list = []
  for kps in np_keypoints:
      keypoints_list.append([list(map(float, kp[:2])) for kp in kps])

  bboxes_list = []
  for bbox in np_bboxes:
      bboxes_list.append(list(map(int, bbox.tolist())))

  scores_list = np_scores.tolist()

  return bboxes_list, keypoints_list, scores_list

def _infer_device() -> torch.device:
    s = (os.environ.get("KPR_CNN_DEVICE") or os.environ.get("TORCH_DEVICE") or "cpu").strip()
    if s.lower() == "cuda" and torch.cuda.is_available():
        return torch.device("cuda")
    if s.lower().startswith("cuda:") and torch.cuda.is_available():
        return torch.device(s)
    return torch.device("cpu")


def predict(images):
  """Run keypoint R-CNN; `images` is a BGR `numpy` image (see `torchvision.transforms.functional.to_tensor`)."""
  device = _infer_device()
  model = _get_model_cached()
  model.to(device)
  model.eval()

  images_input = [F.to_tensor(images)]

  images_input = [image.to(device) for image in images_input]

  with torch.no_grad():
    outputs = model(images_input)

  filtered_outputs = [_filter_output(output) for output in outputs]
  return filtered_outputs

from src.cobb_angle_cal import cobb_angle_cal, keypoints_to_landmark_xy


def kprcnn_to_api_format(bboxes, keypoints, scores, image_shape):
  """Build the JSON API payload: detections, flat landmarks, Cobb angles, curve type, midlines."""
  if not bboxes or not keypoints:
    return {
        "detections": [],
        "landmarks": [],
        "angles": None,
        "curve_type": None,
        "midpoint_lines": None,
        "cobb_error": "No vertebrae detected.",
    }

  detections = []
  for idx, bbox in enumerate(bboxes):
    detections.append({
        "class": 0,
        "confidence": scores[idx],
        "name": "vert",
        "xmin": bbox[0],
        "ymin": bbox[1],
        "xmax": bbox[2],
        "ymax": bbox[3],
    })

  landmarks = []
  for kps in keypoints:
    for kp in kps:
      landmarks.append(kp[0])
      landmarks.append(kp[1])

  curve_type = None
  angles = None
  midpoint_lines = None
  cobb_error: str | None = None
  try:
    _, angles, curve_type, midpoint_lines = cobb_angle_cal(
        keypoints_to_landmark_xy(keypoints), image_shape
    )
  except Exception as e:
    curve_type = None
    angles = None
    midpoint_lines = None
    cobb_error = f"Cobb angle failed: {e!s}"
  return {
      "detections": detections,
      "landmarks": landmarks,
      "angles": angles,
      "curve_type": curve_type,
      "midpoint_lines": midpoint_lines,
      **({"cobb_error": cobb_error} if cobb_error is not None else {}),
  }