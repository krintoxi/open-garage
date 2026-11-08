"""Stand-in for torchmcubes (CUDA/C++ build) using PyMCubes on the CPU, for TripoSR on Apple Silicon.
torchmcubes returns vertices ordered (dim2, dim1, dim0); TripoSR swaps them back, so we return the same order."""
import mcubes
import numpy as np
import torch


def marching_cubes(volume, threshold):
    vol = volume.detach().float().cpu().numpy()
    verts, faces = mcubes.marching_cubes(vol, float(threshold))
    verts = np.ascontiguousarray(verts[:, [2, 1, 0]], dtype=np.float32)
    faces = np.ascontiguousarray(faces[:, [0, 2, 1]], dtype=np.int64)  # keep outward winding after the axis swap
    return torch.from_numpy(verts), torch.from_numpy(faces)
